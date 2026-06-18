import { useState, useEffect, useMemo, useCallback } from 'react'
import { useParams } from 'react-router-dom'
import { useWorkspaceContext } from '../../workspaces/WorkspaceContext'
import { PageHeader } from '../../components/observe/PageHeader'
import { ObservePageToolbar } from '../../components/observe/ObservePageToolbar'
import { MonitoringSettingsModal } from '../../components/observe/MonitoringSettingsModal'
import { HostDetailsDrawer, type HostDetailsHost } from '../../components/observe/HostDetailsDrawer'
import { useObserveAutoRefresh } from '../../hooks/useObserveAutoRefresh'
import { useObserveAccess } from '../../hooks/useObserveAccess'
import { useAiAgentAvailable } from '../../hooks/useAiAgentAvailable'
import { ObserveLoadError } from '../../components/observe/ObserveLoadError'
import { AIAgentDrawer } from '../../components/ai/AIAgentDrawer'
import type { AIAgentSeed } from '../../types/aiAgent'
import { buildHostRuntimeMap, operatingSystemFromTags } from '../../lib/observeHostUtils'
import { useLanguage } from '../../i18n/LanguageContext'
import { gatewayClient } from '../../services/gatewayClient'
import { observeService } from '../../services/observeService'
import type { ObserveServiceRow, ServiceDefinition, ArgsSchemaEntry } from '../../types/observe'
import { getRequestErrorFieldErrors } from '../../lib/requestError'

interface TargetHost {
  id?: number
  name: string
  address: string
  /** Optional public IP (e.g. for agent behind NAT). */
  public_ip?: string | null
  check_command: string
  tags: string[]
  enabled: boolean
  services: TargetService[]
  created_at?: string
  updated_at?: string
}

interface TargetService {
  id?: number
  name: string
  check_command?: string
  check_args?: unknown[]
  service_key?: string
  overrides?: Record<string, unknown>
  enabled: boolean
  /** Seconds between native QynSight checks. Configurable per service. */
  check_interval?: number | null
  /** Seconds before native QynSight retry. Configurable per service. */
  retry_interval?: number | null
}

/** Infer service_key from check_command when API does not send it (so dropdown shows saved type). */
function inferServiceKeyFromCheckCommand(checkCommand: string | undefined): string {
  if (!checkCommand || typeof checkCommand !== 'string') return ''
  const base = checkCommand.split('!')[0].trim().toLowerCase()
  if (base === 'check_http') return 'http'
  if (base === 'check_tcp') return 'tcp_port'
  if (base === 'check_ping') return 'ping'
  return ''
}

/** Infer service_key from service name when check_command is missing (legacy or edge-case data). */
function inferServiceKeyFromServiceName(serviceName: string | undefined): string {
  if (!serviceName || typeof serviceName !== 'string') return ''
  const n = serviceName.trim().toLowerCase().replace(/\s+/g, ' ')
  if (n.includes('http') && !n.includes('tcp') && !/port\s*\d+/.test(n)) return 'http'
  if (n.includes('tcp') || /port\s*\d+/.test(n)) return 'tcp_port'
  if (n.includes('ping') || n.includes('live')) return 'ping'
  return ''
}

/** Ensure overrides is always a plain object (never null, undefined, or array). */
function ensureOverridesObject(o: unknown): Record<string, unknown> {
  if (o != null && typeof o === 'object' && !Array.isArray(o)) {
    return o as Record<string, unknown>
  }
  return {}
}

/** Effective service_key: API, then check_command, then service name (so dropdown always shows when possible). */
function effectiveServiceKey(svc: TargetService): string {
  return (
    svc.service_key ||
    inferServiceKeyFromCheckCommand(svc.check_command) ||
    inferServiceKeyFromServiceName(svc.name) ||
    ''
  )
}

/** Ensure each service has service_key set and overrides is always an object (hydrate from GET/PUT). */
function normalizeHostsServiceKeys(hosts: TargetHost[]): TargetHost[] {
  return hosts.map((host) => ({
    ...host,
    services: host.services.map((svc) => {
      const key = effectiveServiceKey(svc)
      const overrides = ensureOverridesObject(svc.overrides)
      return {
        ...svc,
        ...(key ? { service_key: key } : {}),
        overrides,
      }
    }),
  }))
}

/**
 * Merge PUT response with current state so we never lose service_key or overrides
 * when the API omits them. Match by host name + service name (not index) so order
 * differences between request and response do not assign wrong type to wrong service.
 */
function mergeResponseWithCurrent(
  currentHosts: TargetHost[],
  responseHosts: TargetHost[]
): TargetHost[] {
  const currentByHostAndService = new Map<string, TargetService>()
  currentHosts.forEach((h) => {
    h.services.forEach((s) => {
      currentByHostAndService.set(`${h.name}::${s.name}`, s)
    })
  })
  return responseHosts.map((host) => ({
    ...host,
    services: host.services.map((svc) => {
      const currentSvc = currentByHostAndService.get(`${host.name}::${svc.name}`)
      const serviceKey =
        svc.service_key ||
        currentSvc?.service_key ||
        inferServiceKeyFromCheckCommand(svc.check_command ?? currentSvc?.check_command)
      const fromSvc = ensureOverridesObject(svc.overrides)
      const fromCurrent = ensureOverridesObject(currentSvc?.overrides)
      const overrides = Object.keys(fromSvc).length > 0 ? fromSvc : fromCurrent
      return {
        ...svc,
        service_key: serviceKey || svc.service_key,
        overrides,
      }
    }),
  }))
}

export default function Targets() {
  const { t } = useLanguage()
  const { id } = useParams<{ id: string }>()
  const { selectedWorkspaceId } = useWorkspaceContext()
  const [hosts, setHosts] = useState<TargetHost[]>([])
  const [definitions, setDefinitions] = useState<ServiceDefinition[]>([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState<string | null>(null)
  const [validationErrors, setValidationErrors] = useState<Record<string, string[]>>({})
  const [expandedHosts, setExpandedHosts] = useState<Set<number | string>>(new Set())
  const [expandedServices, setExpandedServices] = useState<Set<string>>(new Set())
  const [settingsOpen, setSettingsOpen] = useState(false)
  const [drawerHostIndex, setDrawerHostIndex] = useState<number | null>(null)
  const [detailHost, setDetailHost] = useState<HostDetailsHost | null>(null)
  const [aiDrawerOpen, setAiDrawerOpen] = useState(false)
  const [aiSeed, setAiSeed] = useState<AIAgentSeed | null>(null)
  const [runtimeServices, setRuntimeServices] = useState<ObserveServiceRow[]>([])
  const [dataRefreshKey, setDataRefreshKey] = useState(0)

  const workspaceId = id || selectedWorkspaceId

  const { canEditConfig: canEdit } = useObserveAccess()
  const aiAvailable = useAiAgentAvailable(workspaceId)

  const hostRuntime = useMemo(() => buildHostRuntimeMap(runtimeServices, workspaceId), [runtimeServices, workspaceId])

  const definitionsByKey = useMemo(() => {
    const map = new Map<string, ServiceDefinition>()
    definitions.forEach((d) => map.set(d.service_key, d))
    return map
  }, [definitions])

  const reloadTargets = useCallback(async () => {
    if (!workspaceId) return
    try {
      setLoading(true)
      setError(null)
      const [targetsResponse, defsResponse, servicesResponse] = await Promise.all([
        gatewayClient.get<TargetHost[] | { data?: TargetHost[] }>(
          `workspaces/${workspaceId}/observe/targets?_t=${Date.now()}`,
          { workspaceId: String(workspaceId), moduleKey: 'qynsight' }
        ),
        observeService.getServiceDefinitions(Number(workspaceId), { engine: 'native', status: 'active' }),
        observeService.getServices(Number(workspaceId), { limit: 500 }),
      ])
      const hostsList: TargetHost[] = Array.isArray(targetsResponse)
        ? targetsResponse
        : typeof targetsResponse === 'object' &&
            targetsResponse !== null &&
            'data' in targetsResponse &&
            Array.isArray((targetsResponse as { data: TargetHost[] }).data)
          ? (targetsResponse as { data: TargetHost[] }).data
          : []
      setHosts(normalizeHostsServiceKeys(hostsList))
      setRuntimeServices(servicesResponse?.items ?? [])
      if (Array.isArray(defsResponse)) {
        setDefinitions(defsResponse)
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : t('targets.error.loadFailed'))
    } finally {
      setLoading(false)
    }
  }, [workspaceId, t])

  const {
    interval,
    setInterval,
    markUpdated,
    refreshNow,
    secondsAgo,
  } = useObserveAutoRefresh(() => {
    setDataRefreshKey((k) => k + 1)
  }, !!workspaceId)

  useEffect(() => {
    void reloadTargets().then(() => markUpdated())
  }, [reloadTargets, dataRefreshKey, markUpdated])

  const handleAddHost = () => {
    setHosts([
      ...hosts,
      {
        name: '',
        address: '',
        public_ip: '',
        check_command: 'check-host-alive',
        tags: [],
        enabled: true,
        services: [],
      },
    ])
  }

  const handleAddService = (hostIndex: number) => {
    const newHosts = [...hosts]
    newHosts[hostIndex].services = [
      ...newHosts[hostIndex].services,
      {
        name: '',
        service_key: '',
        overrides: {},
        enabled: true,
      },
    ]
    setHosts(newHosts)
  }

  const handleRemoveHost = (hostIndex: number) => {
    setHosts(hosts.filter((_, i) => i !== hostIndex))
  }

  const handleRemoveService = (hostIndex: number, serviceIndex: number) => {
    const newHosts = [...hosts]
    newHosts[hostIndex].services = newHosts[hostIndex].services.filter((_, i) => i !== serviceIndex)
    setHosts(newHosts)
  }

  const handleUpdateHost = (hostIndex: number, field: keyof TargetHost, value: unknown) => {
    const newHosts = [...hosts]
    newHosts[hostIndex] = { ...newHosts[hostIndex], [field]: value } as TargetHost
    setHosts(newHosts)
  }

  const handleUpdateService = (
    hostIndex: number,
    serviceIndex: number,
    field: keyof TargetService,
    value: unknown
  ) => {
    const newHosts = [...hosts]
    const services = [...newHosts[hostIndex].services]
    services[serviceIndex] = { ...services[serviceIndex], [field]: value } as TargetService
    newHosts[hostIndex] = { ...newHosts[hostIndex], services }
    if (field === 'service_key' && typeof value === 'string') {
      const def = definitionsByKey.get(value)
      if (def) {
        const defaults: Record<string, unknown> = {}
        def.args_schema.forEach((arg) => {
          if (arg.default !== null && arg.default !== undefined) {
            defaults[arg.key] = arg.default
          }
        })
        newHosts[hostIndex].services[serviceIndex].overrides = ensureOverridesObject(defaults)
      }
    }
    setHosts(newHosts)
  }

  const handleUpdateOverride = (
    hostIndex: number,
    serviceIndex: number,
    key: string,
    value: unknown
  ) => {
    const newHosts = [...hosts]
    const service = newHosts[hostIndex].services[serviceIndex]
    const overrides = { ...ensureOverridesObject(service.overrides) }
    if (value === null || value === undefined || value === '') {
      delete overrides[key]
    } else {
      overrides[key] = value
    }
    service.overrides = overrides
    setHosts(newHosts)
  }

  const handleResetToDefaults = (hostIndex: number, serviceIndex: number) => {
    const service = hosts[hostIndex].services[serviceIndex]
    const def = service.service_key ? definitionsByKey.get(service.service_key) : null
    if (def) {
      const defaults: Record<string, unknown> = {}
      def.args_schema.forEach((arg) => {
        if (arg.default !== null && arg.default !== undefined) {
          defaults[arg.key] = arg.default
        }
      })
      handleUpdateService(hostIndex, serviceIndex, 'overrides', ensureOverridesObject(defaults))
    }
  }

  const handleClearOverride = (hostIndex: number, serviceIndex: number, key: string) => {
    handleUpdateOverride(hostIndex, serviceIndex, key, null)
  }

  const validateClient = (): boolean => {
    const errors: Record<string, string[]> = {}
    for (let hi = 0; hi < hosts.length; hi++) {
      const host = hosts[hi]
      if (!host.name.trim() || !host.address.trim()) {
        errors[`hosts.${hi}.name`] = [t('targets.error.hostNameRequired')]
      }
      for (let si = 0; si < host.services.length; si++) {
        const service = host.services[si]
        if (!service.name.trim()) {
          errors[`hosts.${hi}.services.${si}.name`] = [t('targets.error.serviceNameRequired')]
        }
        const effectiveKey = effectiveServiceKey(service)
        if (!effectiveKey) {
          errors[`hosts.${hi}.services.${si}.service_key`] = [t('targets.error.serviceTypeRequired')]
        } else {
          const def = definitionsByKey.get(effectiveKey)
          if (def) {
            def.args_schema.forEach((arg) => {
              if (arg.required) {
                const val = service.overrides?.[arg.key]
                if (val === null || val === undefined || val === '') {
                  errors[`hosts.${hi}.services.${si}.overrides.${arg.key}`] = [`${arg.key} is required`]
                }
              }
              const val = service.overrides?.[arg.key]
              if (val !== null && val !== undefined && val !== '') {
                const inferredType = inferType(arg, val)
                if (inferredType === 'int' || inferredType === 'float') {
                  const num = Number(val)
                  if (isNaN(num)) {
                    errors[`hosts.${hi}.services.${si}.overrides.${arg.key}`] = [`${arg.key} must be a number`]
                  }
                  if (def.capability_flags.includes('supports_ports') && arg.key === 'port') {
                    const port = Number(val)
                    if (port < 1 || port > 65535) {
                      errors[`hosts.${hi}.services.${si}.overrides.${arg.key}`] = ['Port must be 1-65535']
                    }
                  }
                }
                if (effectiveKey === 'ping' && (arg.key === 'warn_rta_ms' || arg.key === 'crit_rta_ms')) {
                  const plKey = arg.key === 'warn_rta_ms' ? 'warn_pl_pct' : 'crit_pl_pct'
                  const pl = service.overrides?.[plKey]
                  if (pl !== null && pl !== undefined && pl !== '') {
                    const rta = Number(val)
                    const plNum = Number(pl)
                    if (isNaN(rta) || isNaN(plNum) || rta < 0 || plNum < 0 || plNum > 100) {
                      errors[`hosts.${hi}.services.${si}.overrides.${arg.key}`] = ['Invalid format: must be rta,pl% (e.g. 100.0,20%)']
                    }
                  }
                }
              }
            })
          }
        }
      }
    }
    setValidationErrors(errors)
    return Object.keys(errors).length === 0
  }

  const inferType = (arg: ArgsSchemaEntry, val: unknown): string => {
    if (arg.type) return arg.type
    if (typeof val === 'number') return Number.isInteger(val) ? 'int' : 'float'
    if (typeof val === 'boolean') return 'bool'
    if (typeof val === 'object' && val !== null) return 'json'
    return 'string'
  }

  const handleSave = async () => {
    if (!workspaceId) return

    if (!validateClient()) {
      setError('Please fix validation errors before saving')
      return
    }

    try {
      setSaving(true)
      setError(null)
      setSuccess(null)
      setValidationErrors({})

      const payload = {
        hosts: hosts.map((host) => ({
          name: host.name,
          address: host.address,
          public_ip: host.public_ip || undefined,
          check_command: host.check_command,
          tags: host.tags,
          enabled: host.enabled,
          services: host.services.map((service) => {
            const effectiveKey = effectiveServiceKey(service)
            return {
              name: service.name,
              service_key: effectiveKey || undefined,
              check_command: effectiveKey ? undefined : (service.check_command || undefined),
              overrides: ensureOverridesObject(service.overrides),
              enabled: service.enabled,
              check_interval: service.check_interval ?? undefined,
              retry_interval: service.retry_interval ?? undefined,
            }
          }),
        })),
      }

      type PutResult = { targets?: TargetHost[] }
      const result = await gatewayClient.put<TargetHost[] | PutResult>(
        `workspaces/${workspaceId}/observe/targets`,
        payload,
        { workspaceId: String(workspaceId), moduleKey: 'qynsight' }
      )

      const hostsFromResponse = Array.isArray(result) ? result : (result?.targets ?? [])
      const merged = mergeResponseWithCurrent(hosts, hostsFromResponse)
      setHosts(normalizeHostsServiceKeys(merged))

      setSuccess(t('targets.saveSuccess'))
      setError(null)
      setValidationErrors((prev) => {
        const next = { ...prev }
        delete next.native
        return next
      })
    } catch (err: unknown) {
      const fromApi = getRequestErrorFieldErrors(err)
      if (fromApi) {
        setValidationErrors(fromApi as Record<string, string[]>)
        setError(t('targets.error.validationFailed'))
      } else {
        setError(err instanceof Error ? err.message : t('targets.error.saveFailed'))
      }
    } finally {
      setSaving(false)
    }
  }

  const toggleHostExpanded = (hostKey: number | string) => {
    const newExpanded = new Set(expandedHosts)
    if (newExpanded.has(hostKey)) {
      newExpanded.delete(hostKey)
    } else {
      newExpanded.add(hostKey)
    }
    setExpandedHosts(newExpanded)
  }

  const toggleServiceExpanded = (serviceKey: string) => {
    const newExpanded = new Set(expandedServices)
    if (newExpanded.has(serviceKey)) {
      newExpanded.delete(serviceKey)
    } else {
      newExpanded.add(serviceKey)
    }
    setExpandedServices(newExpanded)
  }

  const getFieldError = (path: string): string | undefined => {
    const errs = validationErrors[path]
    return errs && errs.length > 0 ? errs[0] : undefined
  }

  const groupFieldsByCapability = (def: ServiceDefinition): Record<string, ArgsSchemaEntry[]> => {
    const groups: Record<string, ArgsSchemaEntry[]> = {}
    const sorted = [...def.args_schema].sort((a, b) => a.position - b.position)
    sorted.forEach((arg) => {
      let assigned = false
      for (const flag of def.capability_flags) {
        if (flag === 'supports_thresholds' && (arg.key.includes('warn') || arg.key.includes('crit') || arg.key.includes('threshold'))) {
          if (!groups['Thresholds']) groups['Thresholds'] = []
          groups['Thresholds'].push(arg)
          assigned = true
          break
        }
        if (flag === 'supports_ports' && (arg.key === 'port' || arg.key.includes('port'))) {
          if (!groups['Port']) groups['Port'] = []
          groups['Port'].push(arg)
          assigned = true
          break
        }
        if (flag === 'supports_urls' && (arg.key === 'path' || arg.key === 'url' || arg.key.includes('url'))) {
          if (!groups['URL']) groups['URL'] = []
          groups['URL'].push(arg)
          assigned = true
          break
        }
        if (flag === 'supports_auth' && (arg.key === 'basic_auth' || arg.key.includes('auth') || arg.key.includes('credential'))) {
          if (!groups['Auth']) groups['Auth'] = []
          groups['Auth'].push(arg)
          assigned = true
          break
        }
        if (flag === 'supports_payload' && (arg.key.includes('payload') || arg.key.includes('body'))) {
          if (!groups['Payload']) groups['Payload'] = []
          groups['Payload'].push(arg)
          assigned = true
          break
        }
      }
      if (!assigned) {
        if (!groups['General']) groups['General'] = []
        groups['General'].push(arg)
      }
    })
    return groups
  }

  const renderField = (
    arg: ArgsSchemaEntry,
    value: unknown,
    onChange: (val: unknown) => void,
    onClear: () => void,
    error?: string
  ) => {
    const inferredType = inferType(arg, value ?? arg.default)
    const hasOverride = value !== null && value !== undefined && value !== ''
    const displayValue = hasOverride ? value : arg.default

    return (
      <div key={arg.key} className="space-y-1">
        <div className="flex items-center gap-2">
          <label className="text-xs text-white/70 flex-1">
            {arg.key}
            {arg.required && <span className="text-rose-400 ml-1">*</span>}
            {arg.help && (
              <span className="text-white/40 ml-2 text-[10px]">({arg.help})</span>
            )}
          </label>
          {hasOverride && (
            <button
              type="button"
              onClick={onClear}
              className="text-xs text-sky-400 hover:text-sky-300"
            >
              Clear
            </button>
          )}
        </div>
        {inferredType === 'bool' ? (
          <input
            type="checkbox"
            checked={displayValue === true}
            onChange={(e) => onChange(e.target.checked)}
            disabled={saving}
            className="rounded border-white/20"
          />
        ) : inferredType === 'int' || inferredType === 'float' ? (
          <input
            type="number"
            step={inferredType === 'float' ? 'any' : '1'}
            value={displayValue !== null && displayValue !== undefined ? String(displayValue) : ''}
            onChange={(e) => {
              const v = e.target.value
              if (v === '') {
                onChange(null)
              } else {
                onChange(inferredType === 'int' ? parseInt(v, 10) : parseFloat(v))
              }
            }}
            disabled={saving}
            className="w-full rounded border border-white/10 bg-white/5 px-2 py-1 text-xs text-white placeholder:text-white/40 disabled:opacity-50"
          />
        ) : inferredType === 'json' ? (
          <textarea
            value={displayValue !== null && displayValue !== undefined ? JSON.stringify(displayValue, null, 2) : ''}
            onChange={(e) => {
              try {
                onChange(e.target.value ? JSON.parse(e.target.value) : null)
              } catch {
                onChange(e.target.value)
              }
            }}
            disabled={saving}
            rows={3}
            className="w-full rounded border border-white/10 bg-white/5 px-2 py-1 text-xs text-white placeholder:text-white/40 disabled:opacity-50 font-mono"
          />
        ) : (
          <input
            type="text"
            value={displayValue !== null && displayValue !== undefined ? String(displayValue) : ''}
            onChange={(e) => onChange(e.target.value || null)}
            disabled={saving}
            className="w-full rounded border border-white/10 bg-white/5 px-2 py-1 text-xs text-white placeholder:text-white/40 disabled:opacity-50"
          />
        )}
        {error && <div className="text-xs text-rose-400">{error}</div>}
      </div>
    )
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className="text-sm text-white/60">{t('targets.loading')}</div>
      </div>
    )
  }

  if (error && hosts.length === 0) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('targets.title')} subtitle={t('targets.subtitle')} />
        <ObserveLoadError
          message={t('observe.error.hosts')}
          retryLabel={t('observe.loadError.retry')}
          onRetry={() => {
            setDataRefreshKey((k) => k + 1)
            void reloadTargets()
          }}
        />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('targets.title')}
        subtitle={t('targets.subtitle')}
        actions={
          <>
            <ObservePageToolbar
              interval={interval}
              onIntervalChange={setInterval}
              secondsAgo={secondsAgo}
              onRefresh={() => {
                setDataRefreshKey((k) => k + 1)
                refreshNow()
              }}
              refreshing={loading}
              settingsLabel={canEdit ? t('targets.monitoringSettings') : undefined}
              onSettings={canEdit ? () => setSettingsOpen(true) : undefined}
            />
            {canEdit && (
              <>
                <button
                  onClick={handleAddHost}
                  disabled={saving}
                  className="rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white/70 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-white/10"
                >
                  {t('targets.addHost')}
                </button>
                <button
                  onClick={handleSave}
                  disabled={saving}
                  className="rounded-lg border border-sky-500/30 bg-sky-500/20 px-4 py-1.5 text-xs font-medium text-sky-200 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-sky-500/30"
                >
                  {saving ? t('targets.saving') : t('targets.save')}
                </button>
              </>
            )}
          </>
        }
      />

      {workspaceId && canEdit && (
        <MonitoringSettingsModal
          open={settingsOpen}
          onClose={() => setSettingsOpen(false)}
          workspaceId={workspaceId}
          canEdit={canEdit}
        />
      )}

      {error && (
        <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100 space-y-2">
          <div>{error}</div>
          {validationErrors.native?.length > 0 && (
            <ul className="list-disc list-inside text-rose-200/90 text-xs space-y-1">
              {validationErrors.native.map((msg, i) => (
                <li key={i}>{msg}</li>
              ))}
            </ul>
          )}
        </div>
      )}

      {success && (
        <div className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
          {success}
        </div>
      )}

      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
        <div className="overflow-x-auto">
          <table className="w-full border-collapse text-sm">
            <thead>
              <tr className="border-b border-white/10 text-left text-xs text-white/60">
                <th className="px-3 py-2">{t('targets.col.host')}</th>
                <th className="px-3 py-2">{t('targets.col.ip')}</th>
                <th className="px-3 py-2">{t('hosts.col.os')}</th>
                <th className="px-3 py-2">{t('targets.col.status')}</th>
                <th className="px-3 py-2">{t('targets.col.services')}</th>
                <th className="px-3 py-2">{t('targets.col.lastCheck')}</th>
                <th className="px-3 py-2 text-end">{t('hosts.col.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {hosts.length === 0 ? (
                <tr>
                  <td colSpan={7} className="px-3 py-8 text-center text-white/60">
                    {t('targets.empty')}
                  </td>
                </tr>
              ) : (
                hosts.map((host, hostIndex) => {
                  const runtime = hostRuntime.get(host.name)
                  const os = operatingSystemFromTags(host.tags)
                  return (
                    <tr
                      key={host.id ?? host.name}
                      className="border-b border-white/5 hover:bg-white/5"
                    >
                      <td className="px-3 py-2.5 font-medium">{host.name || '—'}</td>
                      <td className="px-3 py-2.5 text-white/70">{host.address || '—'}</td>
                      <td className="px-3 py-2.5 text-white/70">{os ?? t('hosts.osUnknown')}</td>
                      <td className={`px-3 py-2.5 uppercase text-xs ${runtime?.status === 'critical' ? 'text-rose-300' : runtime?.status === 'warning' ? 'text-amber-300' : runtime?.status === 'ok' ? 'text-emerald-300' : 'text-white/60'}`}>
                        {runtime?.status ?? t('targets.status.unknown')}
                      </td>
                      <td className="px-3 py-2.5">{runtime?.serviceCheckCount ?? host.services.length}</td>
                      <td className="px-3 py-2.5 font-mono text-xs text-white/70">
                        {runtime?.lastCheck
                          ? new Date(runtime.lastCheck).toLocaleString()
                          : '—'}
                      </td>
                      <td className="px-3 py-2.5">
                        <div className="flex justify-end gap-2">
                          <button
                            type="button"
                            onClick={() => setDetailHost(host)}
                            className="text-xs text-sky-400 hover:text-sky-300"
                          >
                            {t('hosts.action.viewDetails')}
                          </button>
                          {canEdit ? (
                            <button
                              type="button"
                              onClick={() => {
                                setDrawerHostIndex(hostIndex)
                                setExpandedHosts(new Set([host.id ?? `new-${hostIndex}`]))
                              }}
                              className="text-xs text-white/60 hover:text-white"
                            >
                              {t('hosts.drawer.configure')}
                            </button>
                          ) : null}
                        </div>
                      </td>
                    </tr>
                  )
                })
              )}
            </tbody>
          </table>
        </div>
      </div>

      {drawerHostIndex !== null && hosts[drawerHostIndex] ? (
        <div className="fixed inset-0 z-50 flex justify-end bg-black/50">
          <button
            type="button"
            className="flex-1"
            onClick={() => setDrawerHostIndex(null)}
            aria-label={t('common.close')}
          />
          <div className="flex h-full w-full min-w-0 max-w-3xl flex-col overflow-hidden border-s border-white/10 bg-[#0f151d] text-white" data-drawer-panel="true">
            <div className="flex items-center justify-between border-b border-white/10 px-5 py-4">
              <h3 className="text-base font-semibold">{t('targets.configDrawer')}</h3>
              <button
                type="button"
                onClick={() => setDrawerHostIndex(null)}
                className="rounded border border-white/10 px-2 py-1 text-xs text-white/70 hover:bg-white/10"
              >
                {t('common.close')}
              </button>
            </div>
            <div className="min-w-0 flex-1 overflow-y-auto overflow-x-hidden p-4">
      <div className="min-w-0 space-y-4">
        {(() => {
          const hostIndex = drawerHostIndex
          const host = hosts[hostIndex]
          const hostKey = host.id ?? `new-${hostIndex}`
          return (
              <div key={hostKey} className="min-w-0 rounded-lg border border-white/10 bg-white/5 p-4">
                <div className="flex flex-col gap-3">
                  <div className="flex flex-wrap items-center gap-2">
                    <button
                      onClick={() => toggleHostExpanded(hostKey)}
                      className="shrink-0 text-white/60 hover:text-white transition"
                    >
                      <svg
                        width="16"
                        height="16"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2"
                        className={expandedHosts.has(hostKey) ? 'rotate-90' : ''}
                      >
                        <polyline points="9 18 15 12 9 6" />
                      </svg>
                    </button>
                    {canEdit ? (
                      <div className="grid min-w-0 flex-1 grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3">
                        <input
                          type="text"
                          placeholder={t('targets.hostName')}
                          value={host.name}
                          onChange={(e) => handleUpdateHost(hostIndex, 'name', e.target.value)}
                          disabled={saving}
                          className={`min-w-0 w-full rounded-lg border px-3 py-1.5 text-xs text-white placeholder:text-white/40 disabled:opacity-50 ${
                            getFieldError(`hosts.${hostIndex}.name`)
                              ? 'border-rose-500/50 bg-rose-500/10'
                              : 'border-white/10 bg-white/5'
                          }`}
                        />
                        <input
                          type="text"
                          placeholder={t('targets.privateIp')}
                          value={host.address}
                          onChange={(e) => handleUpdateHost(hostIndex, 'address', e.target.value)}
                          disabled={saving}
                          className={`min-w-0 w-full rounded-lg border px-3 py-1.5 text-xs text-white placeholder:text-white/40 disabled:opacity-50 ${
                            getFieldError(`hosts.${hostIndex}.address`)
                              ? 'border-rose-500/50 bg-rose-500/10'
                              : 'border-white/10 bg-white/5'
                          }`}
                        />
                        <input
                          type="text"
                          placeholder={t('targets.publicIp')}
                          value={host.public_ip ?? ''}
                          onChange={(e) => handleUpdateHost(hostIndex, 'public_ip', e.target.value)}
                          disabled={saving}
                          className="min-w-0 w-full rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white placeholder:text-white/40 disabled:opacity-50"
                        />
                        <input
                          type="text"
                          placeholder={t('targets.checkCommand')}
                          value={host.check_command}
                          onChange={(e) => handleUpdateHost(hostIndex, 'check_command', e.target.value)}
                          disabled={saving}
                          className="min-w-0 w-full rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white placeholder:text-white/40 disabled:opacity-50 sm:col-span-2 xl:col-span-3"
                        />
                      </div>
                    ) : (
                      <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                        <span className="text-sm font-medium text-white">{host.name}</span>
                        <span className="text-xs text-white/60">{host.address}</span>
                        <span className="text-xs text-white/50">{host.public_ip ?? '—'}</span>
                        <span className="text-xs text-white/50">{host.check_command}</span>
                      </div>
                    )}
                    <div className="flex flex-wrap items-center gap-3">
                    <label className="flex items-center gap-2 text-xs text-white/70">
                      <input
                        type="checkbox"
                        checked={host.enabled}
                        onChange={(e) => handleUpdateHost(hostIndex, 'enabled', e.target.checked)}
                        disabled={saving || !canEdit}
                        className="rounded border-white/20"
                      />
                      {t('targets.enabled')}
                    </label>
                    {canEdit && (
                      <button
                        onClick={() => handleRemoveHost(hostIndex)}
                        disabled={saving}
                        className="text-rose-400 hover:text-rose-300 text-xs disabled:opacity-50"
                      >
                        {t('targets.removeHost')}
                      </button>
                    )}
                    </div>
                  </div>
                </div>

                {expandedHosts.has(hostKey) && (
                  <div className="mt-4 space-y-2 pl-6">
                    <div className="flex items-center justify-between mb-2">
                      <span className="text-xs font-medium text-white/70">{t('targets.servicesSection')}</span>
                      {canEdit && (
                        <button
                          onClick={() => handleAddService(hostIndex)}
                          disabled={saving}
                          className="text-xs text-sky-400 hover:text-sky-300 disabled:opacity-50"
                        >
                          + {t('targets.addServiceCheck')}
                        </button>
                      )}
                    </div>
                    {host.services.length === 0 ? (
                      <div className="text-xs text-white/50">{t('targets.noServiceChecks')}</div>
                    ) : (
                      host.services.map((service, serviceIndex) => {
                        const serviceKey = `${hostIndex}-${serviceIndex}`
                        const displayedServiceKey = effectiveServiceKey(service)
                        const def = displayedServiceKey ? definitionsByKey.get(displayedServiceKey) : null
                        const groups = def ? groupFieldsByCapability(def) : {}
                        return (
                          <div key={serviceIndex} className="min-w-0 rounded border border-white/5 bg-white/5 p-3 space-y-3">
                            <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                              {canEdit ? (
                                <>
                                  <input
                                    type="text"
                                    placeholder={t('targets.serviceCheckName')}
                                    value={service.name}
                                    onChange={(e) => handleUpdateService(hostIndex, serviceIndex, 'name', e.target.value)}
                                    disabled={saving}
                                    className={`min-w-0 w-full flex-1 rounded border px-2 py-1 text-xs text-white placeholder:text-white/40 disabled:opacity-50 sm:min-w-[10rem] ${
                                      getFieldError(`hosts.${hostIndex}.services.${serviceIndex}.name`)
                                        ? 'border-rose-500/50 bg-rose-500/10'
                                        : 'border-white/10 bg-white/5'
                                    }`}
                                  />
                                  <select
                                    value={displayedServiceKey || ''}
                                    onChange={(e) => handleUpdateService(hostIndex, serviceIndex, 'service_key', e.target.value)}
                                    disabled={saving}
                                    className={`min-w-0 w-full flex-1 rounded border px-2 py-1 text-xs text-white disabled:opacity-50 sm:min-w-[12rem] ${
                                      getFieldError(`hosts.${hostIndex}.services.${serviceIndex}.service_key`) ||
                                      getFieldError(`hosts.${hostIndex}.services.${serviceIndex}.check_command`)
                                        ? 'border-rose-500/50 bg-rose-500/10'
                                        : 'border-white/10 bg-white/5'
                                    }`}
                                  >
                                    <option value="">{t('targets.selectServiceCheckType')}</option>
                                    {definitions.map((d) => (
                                      <option key={d.service_key} value={d.service_key}>
                                        {d.display_name}
                                      </option>
                                    ))}
                                  </select>
                                </>
                              ) : (
                                <>
                                  <span className="flex-1 text-xs text-white">{service.name}</span>
                                  <span className="flex-1 text-xs text-white/60">
                                    {def ? def.display_name : service.check_command}
                                  </span>
                                </>
                              )}
                              <label className="flex items-center gap-1 text-xs text-white/70">
                                <input
                                  type="checkbox"
                                  checked={service.enabled}
                                  onChange={(e) =>
                                    handleUpdateService(hostIndex, serviceIndex, 'enabled', e.target.checked)
                                  }
                                  disabled={saving || !canEdit}
                                  className="rounded border-white/20"
                                />
                                {t('targets.enabled')}
                              </label>
                              {canEdit && (
                                <button
                                  onClick={() => handleRemoveService(hostIndex, serviceIndex)}
                                  disabled={saving}
                                  className="text-rose-400 hover:text-rose-300 text-xs disabled:opacity-50"
                                >
                                  {t('targets.removeServiceCheck')}
                                </button>
                              )}
                            </div>
                            {def && displayedServiceKey && (
                              <>
                                {def.description && (
                                  <p className="text-xs text-white/60 leading-relaxed max-w-2xl">
                                    {def.description}
                                  </p>
                                )}
                                <button
                                  type="button"
                                  onClick={() => toggleServiceExpanded(serviceKey)}
                                  className="text-xs text-sky-400 hover:text-sky-300"
                                >
                                  {expandedServices.has(serviceKey) ? '▼' : '▶'} {t('targets.serviceConfiguration')}
                                </button>
                                {expandedServices.has(serviceKey) && (
                                  <div className="min-w-0 space-y-4 ps-4 border-s border-white/10">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                      <span className="text-xs font-medium text-white/70">{t('targets.serviceConfiguration')}</span>
                                      <button
                                        type="button"
                                        onClick={() => handleResetToDefaults(hostIndex, serviceIndex)}
                                        className="text-xs text-sky-400 hover:text-sky-300"
                                      >
                                        {t('targets.resetDefaults')}
                                      </button>
                                    </div>
                                    <div className="space-y-2">
                                      <div className="text-xs font-medium text-white/60">{t('targets.pollingTitle')}</div>
                                      <div className="flex flex-wrap items-center gap-3">
                                        <label className="flex flex-wrap items-center gap-2 text-xs text-white/80">
                                          {t('targets.checkIntervalMin')}:
                                          <input
                                            type="number"
                                            min={1}
                                            max={86400}
                                            value={service.check_interval ?? ''}
                                            onChange={(e) => {
                                              const v = e.target.value
                                              handleUpdateService(hostIndex, serviceIndex, 'check_interval', v === '' ? null : parseInt(v, 10))
                                            }}
                                            className="w-20 rounded border border-white/20 bg-white/5 px-2 py-1 text-white"
                                            placeholder="5"
                                          />
                                        </label>
                                        <label className="flex flex-wrap items-center gap-2 text-xs text-white/80">
                                          {t('targets.retryIntervalMin')}:
                                          <input
                                            type="number"
                                            min={1}
                                            max={86400}
                                            value={service.retry_interval ?? ''}
                                            onChange={(e) => {
                                              const v = e.target.value
                                              handleUpdateService(hostIndex, serviceIndex, 'retry_interval', v === '' ? null : parseInt(v, 10))
                                            }}
                                            className="w-20 rounded border border-white/20 bg-white/5 px-2 py-1 text-white"
                                            placeholder="1"
                                          />
                                        </label>
                                      </div>
                                      <p className="text-[10px] text-white/50">{t('targets.pollingHint')}</p>
                                    </div>
                                    {Object.entries(groups).map(([sectionName, fields]) => (
                                      <div key={sectionName} className="space-y-2">
                                        <div className="text-xs font-medium text-white/60">{sectionName}</div>
                                        {fields.map((arg) => {
                                          const value = service.overrides?.[arg.key]
                                          const error = getFieldError(
                                            `hosts.${hostIndex}.services.${serviceIndex}.overrides.${arg.key}`
                                          )
                                          return renderField(
                                            arg,
                                            value,
                                            (val) => handleUpdateOverride(hostIndex, serviceIndex, arg.key, val),
                                            () => handleClearOverride(hostIndex, serviceIndex, arg.key),
                                            error
                                          )
                                        })}
                                      </div>
                                    ))}
                                  </div>
                                )}
                              </>
                            )}
                          </div>
                        )
                      })
                    )}
                  </div>
                )}
              </div>
          )
        })()}
      </div>
            </div>
          </div>
        </div>
      ) : null}

      {workspaceId && detailHost ? (
        <HostDetailsDrawer
          open={!!detailHost}
          onClose={() => setDetailHost(null)}
          workspaceId={Number(workspaceId)}
          host={detailHost}
          serviceRows={runtimeServices}
          showAiAnalyze={aiAvailable}
          onAnalyzeHealth={() => {
            setAiSeed({
              id: Date.now(),
              agent: 'anomaly_detector',
              question: `Analyze health and risks for host ${detailHost.name} (${detailHost.address}).`,
              context: { host: detailHost.name },
              autoSend: true,
            })
            setAiDrawerOpen(true)
          }}
          onConfigure={
            canEdit
              ? () => {
                  const idx = hosts.findIndex((h) => h.name === detailHost.name)
                  if (idx >= 0) {
                    setDetailHost(null)
                    setDrawerHostIndex(idx)
                    setExpandedHosts(new Set([hosts[idx].id ?? `new-${idx}`]))
                  }
                }
              : undefined
          }
        />
      ) : null}

      {workspaceId ? (
        <AIAgentDrawer
          open={aiDrawerOpen}
          workspaceId={Number(workspaceId)}
          seed={aiSeed}
          onClose={() => {
            setAiDrawerOpen(false)
            setAiSeed(null)
          }}
        />
      ) : null}
    </div>
  )
}
