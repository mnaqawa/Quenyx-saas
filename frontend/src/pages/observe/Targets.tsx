import { useState, useEffect, useMemo, useCallback, useRef } from 'react'
import { useObserveWorkspaceId } from '../../hooks/useObserveWorkspaceId'
import { PageHeader } from '../../components/observe/PageHeader'
import { QuenyxAiButton } from '../../components/observe/intelligence/QuenyxAiButton'
import { ObservePageToolbar } from '../../components/observe/ObservePageToolbar'
import { MonitoringSettingsModal } from '../../components/observe/MonitoringSettingsModal'
import { HostDetailsDrawer, type HostDetailsHost } from '../../components/observe/HostDetailsDrawer'
import { ObservePasswordInput } from '../../components/observe/ObservePasswordInput'
import { ObserveServiceTypeSelect } from '../../components/observe/ObserveServiceTypeSelect'
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
import { parseTargetsResponse } from '../../lib/observeTargets'
import { HostLifecycleMenu, lifecycleStatusClass, lifecycleStatusLabel } from '../../components/platform/HostLifecycleMenu'
import {
  normalizeServiceIntervalsForUi,
  serviceIntervalsForApi,
  DEFAULT_CHECK_INTERVAL_MIN,
  DEFAULT_RETRY_INTERVAL_MIN,
} from '../../lib/observeIntervals'

interface TargetHost {
  id?: number
  uuid?: string
  name: string
  address: string
  /** Optional public IP (e.g. for agent behind NAT). */
  public_ip?: string | null
  check_command: string
  tags: string[]
  enabled: boolean
  lifecycle_status?: string
  lifecycle_reason?: string | null
  agent_id?: string | null
  source?: string
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
  /** Secret fields stored server-side (password not returned on GET). */
  configured_secrets?: string[]
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
  if (n.includes('mysql') || n.includes('mariadb') || /\bdb\b/.test(n)) return 'mysql'
  if (n.includes('postgres') || n.includes('pgsql')) return 'pgsql'
  if (n.includes('ssl') || n.includes('certificate') || n.includes('cert')) return 'ssl_validity'
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
        configured_secrets: Array.isArray(svc.configured_secrets) ? svc.configured_secrets : [],
        ...normalizeServiceIntervalsForUi(svc),
      }
    }),
  }))
}

function serializeHosts(hosts: TargetHost[]): string {
  return JSON.stringify(normalizeHostsServiceKeys(hosts))
}

function fieldLabel(key: string): string {
  const labels: Record<string, string> = {
    url: 'URL',
    urls: 'URLs (one per line)',
    hostname: 'Hostname',
    host: 'Host / IP',
    plugin: 'Script name',
    args: 'Extra arguments (JSON)',
    logfile: 'Log file path',
    pattern: 'Search pattern',
    path: 'Path / file',
    warn_sec: 'Warning (seconds)',
    crit_sec: 'Critical (seconds)',
    port: 'Port',
    password: 'Password',
    user: 'Username',
    database: 'Database',
    expect: 'Expected HTTP status',
    use_ssl: 'Use HTTPS',
    warn_days: 'Warning (days before expiry)',
    crit_days: 'Critical (days before expiry)',
  }
  return labels[key] ?? key.replace(/_/g, ' ')
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
      const configuredSecrets =
        (svc.configured_secrets && svc.configured_secrets.length > 0
          ? svc.configured_secrets
          : currentSvc?.configured_secrets) ?? []
      return {
        ...svc,
        service_key: serviceKey || svc.service_key,
        overrides,
        configured_secrets: configuredSecrets,
      }
    }),
  }))
}

export default function Targets() {
  const { t } = useLanguage()
  const workspaceId = useObserveWorkspaceId()
  const [hosts, setHosts] = useState<TargetHost[]>([])
  const [definitions, setDefinitions] = useState<ServiceDefinition[]>([])
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
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
  const [lifecycleFilter, setLifecycleFilter] = useState<string>('default')
  const [savedSnapshot, setSavedSnapshot] = useState('[]')
  const isDirtyRef = useRef(false)
  const serviceListEndRef = useRef<HTMLDivElement>(null)
  const fetchInFlightRef = useRef(false)
  const lastFetchAtRef = useRef(0)
  const MIN_TARGETS_FETCH_MS = 2000

  const isDirty = useMemo(
    () => serializeHosts(hosts) !== savedSnapshot,
    [hosts, savedSnapshot],
  )

  useEffect(() => {
    isDirtyRef.current = isDirty
  }, [isDirty])

  const { canEditConfig: canEdit } = useObserveAccess()
  const aiAvailable = useAiAgentAvailable(workspaceId)

  const hostRuntime = useMemo(() => buildHostRuntimeMap(runtimeServices, workspaceId), [runtimeServices, workspaceId])

  const definitionsByKey = useMemo(() => {
    const map = new Map<string, ServiceDefinition>()
    definitions.forEach((d) => map.set(d.service_key, d))
    return map
  }, [definitions])

  const reloadTargets = useCallback(async (options?: { force?: boolean; background?: boolean }) => {
    if (!workspaceId) return
    const force = options?.force === true
    const background = options?.background === true
    const now = Date.now()
    if (background && !force && now - lastFetchAtRef.current < MIN_TARGETS_FETCH_MS) {
      return
    }
    if (fetchInFlightRef.current) {
      return
    }
    fetchInFlightRef.current = true
    try {
      if (!force && isDirtyRef.current) {
        setError(null)
        const servicesResponse = await observeService.getServices(Number(workspaceId), { limit: 500 })
        setRuntimeServices(servicesResponse?.items ?? [])
        return
      }

      if (!background) {
        setLoading(true)
      } else {
        setRefreshing(true)
      }
      setError(null)
      const [targetsResponse, defsResponse, servicesResponse] = await Promise.all([
        gatewayClient.get<TargetHost[] | { data?: TargetHost[]; targets?: TargetHost[] }>(
          `workspaces/${workspaceId}/observe/targets?lifecycle=${lifecycleFilter}&_t=${Date.now()}`,
          { workspaceId: String(workspaceId), moduleKey: 'qynsight' }
        ),
        observeService.getServiceDefinitions(Number(workspaceId), { engine: 'native', status: 'active' }),
        observeService.getServices(Number(workspaceId), { limit: 500 }),
      ])
      const hostsList = parseTargetsResponse(targetsResponse) as TargetHost[]
      const normalized = normalizeHostsServiceKeys(hostsList)
      setHosts(normalized)
      setSavedSnapshot(serializeHosts(normalized))
      setRuntimeServices(servicesResponse?.items ?? [])
      if (Array.isArray(defsResponse)) {
        setDefinitions(defsResponse)
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load targets')
    } finally {
      if (!background) {
        setLoading(false)
      }
      setRefreshing(false)
      fetchInFlightRef.current = false
      lastFetchAtRef.current = Date.now()
    }
  }, [workspaceId, lifecycleFilter])

  const bumpRefreshKey = useCallback(() => {
    setDataRefreshKey((k) => k + 1)
  }, [])

  const {
    interval,
    setInterval,
    markUpdated,
    refreshNow,
    secondsAgo,
  } = useObserveAutoRefresh(bumpRefreshKey, !!workspaceId)

  useEffect(() => {
    const isBackground = dataRefreshKey > 0
    void reloadTargets({ background: isBackground }).then(() => markUpdated())
  }, [reloadTargets, dataRefreshKey, markUpdated])

  useEffect(() => {
    if (drawerHostIndex === null) return
    const host = hosts[drawerHostIndex]
    if (!host) return
    const hostKey = host.id ?? `new-${drawerHostIndex}`
    setExpandedHosts(new Set([hostKey]))
  }, [drawerHostIndex, hosts])

  const handleAddHost = () => {
    const newIndex = hosts.length
    const hostKey = `new-${newIndex}`
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
    setDrawerHostIndex(newIndex)
    setExpandedHosts(new Set([hostKey]))
  }

  const handleAddService = (hostIndex: number) => {
    const newServiceIndex = hosts[hostIndex].services.length
    const serviceKey = `${hostIndex}-${newServiceIndex}`
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
    setExpandedServices((prev) => new Set([...prev, serviceKey]))
    window.requestAnimationFrame(() => {
      serviceListEndRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
    })
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
      if (value) {
        setExpandedServices((prev) => new Set([...prev, `${hostIndex}-${serviceIndex}`]))
      }
    }
    setHosts(newHosts)
  }

  const isSecretField = (arg: ArgsSchemaEntry): boolean =>
    arg.type === 'password' ||
    arg.key === 'password' ||
    arg.key.endsWith('_password') ||
    arg.key === 'secret' ||
    arg.key === 'basic_auth'

  const handleUpdateOverride = (
    hostIndex: number,
    serviceIndex: number,
    key: string,
    value: unknown,
    isSecret = false
  ) => {
    const newHosts = [...hosts]
    const service = newHosts[hostIndex].services[serviceIndex]
    const overrides = { ...ensureOverridesObject(service.overrides) }
    if (value === null || value === undefined || value === '') {
      if (isSecret) {
        overrides[key] = ''
        service.configured_secrets = (service.configured_secrets ?? []).filter((k) => k !== key)
      } else {
        delete overrides[key]
      }
    } else {
      overrides[key] = value
      if (isSecret && !(service.configured_secrets ?? []).includes(key)) {
        service.configured_secrets = [...(service.configured_secrets ?? []), key]
      }
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

  const handleClearOverride = (hostIndex: number, serviceIndex: number, key: string, isSecret = false) => {
    handleUpdateOverride(hostIndex, serviceIndex, key, isSecret ? '' : null, isSecret)
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
                const secretConfigured = service.configured_secrets?.includes(arg.key) ?? false
                if ((val === null || val === undefined || val === '') && !secretConfigured) {
                  errors[`hosts.${hi}.services.${si}.overrides.${arg.key}`] = [`${fieldLabel(arg.key)} is required`]
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
    if (arg.type === 'password' || arg.key === 'password' || arg.key.endsWith('_password') || arg.key === 'secret') {
      return 'password'
    }
    if (arg.type === 'textarea' || arg.key === 'urls') {
      return 'textarea'
    }
    if (arg.type) return arg.type
    if (typeof val === 'number') return Number.isInteger(val) ? 'int' : 'float'
    if (typeof val === 'boolean') return 'bool'
    if (typeof val === 'object' && val !== null) return 'json'
    return 'string'
  }

  const handleSave = async (): Promise<boolean> => {
    if (!workspaceId) return false

    if (!validateClient()) {
      setError('Please fix validation errors before saving')
      return false
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
            const overrides = { ...ensureOverridesObject(service.overrides) }
            const def = effectiveKey ? definitionsByKey.get(effectiveKey) : null
            if (def) {
              def.args_schema.forEach((arg) => {
                if (isSecretField(arg) && overrides[arg.key] === '') {
                  overrides[arg.key] = ''
                }
              })
            }
            // Omit untouched secret keys so the server preserves stored values.
            if (def) {
              def.args_schema.forEach((arg) => {
                if (
                  isSecretField(arg) &&
                  !(service.configured_secrets ?? []).includes(arg.key) &&
                  (overrides[arg.key] === undefined || overrides[arg.key] === null || overrides[arg.key] === '')
                ) {
                  delete overrides[arg.key]
                }
              })
            }
            const intervals = serviceIntervalsForApi(service)
            return {
              name: service.name,
              service_key: effectiveKey || undefined,
              check_command: effectiveKey ? undefined : (service.check_command || undefined),
              overrides,
              enabled: service.enabled,
              ...intervals,
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

      const hostsFromResponse = parseTargetsResponse(result) as TargetHost[]
      const merged = mergeResponseWithCurrent(hosts, hostsFromResponse)
      const normalized = normalizeHostsServiceKeys(merged)
      setHosts(normalized)
      setSavedSnapshot(serializeHosts(normalized))

      setSuccess(t('targets.saveSuccess'))
      setError(null)
      setValidationErrors((prev) => {
        const next = { ...prev }
        delete next.native
        return next
      })

      const servicesResponse = await observeService.getServices(Number(workspaceId), { limit: 500 })
      setRuntimeServices(servicesResponse?.items ?? [])
      return true
    } catch (err: unknown) {
      const fromApi = getRequestErrorFieldErrors(err)
      if (fromApi) {
        setValidationErrors(fromApi as Record<string, string[]>)
        setError(t('targets.error.validationFailed'))
      } else {
        setError(err instanceof Error ? err.message : t('targets.error.saveFailed'))
      }
      return false
    } finally {
      setSaving(false)
    }
  }

  const handleSaveFromDrawer = async (closeAfter = false) => {
    const ok = await handleSave()
    if (ok && closeAfter) {
      setDrawerHostIndex(null)
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
      if (arg.key === 'host' || arg.key === 'database') {
        if (!groups['Connection']) groups['Connection'] = []
        groups['Connection'].push(arg)
        assigned = true
      }
      if (arg.key === 'plugin' || arg.key === 'args') {
        if (!groups['Script']) groups['Script'] = []
        groups['Script'].push(arg)
        assigned = true
      }
      if (arg.key === 'urls') {
        if (!groups['URLs']) groups['URLs'] = []
        groups['URLs'].push(arg)
        assigned = true
      }
      for (const flag of def.capability_flags) {
        if (assigned) break
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
        if (flag === 'supports_urls' && (arg.key === 'path' || arg.key === 'url' || arg.key === 'hostname' || arg.key.includes('url'))) {
          if (!groups['URL']) groups['URL'] = []
          groups['URL'].push(arg)
          assigned = true
          break
        }
        if (flag === 'supports_auth' && (arg.key === 'basic_auth' || arg.key.includes('auth') || arg.key.includes('credential') || arg.key === 'password' || arg.key === 'user' || arg.key === 'secret')) {
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
    error?: string,
    configuredSecrets: string[] = []
  ) => {
    const inferredType = inferType(arg, value ?? arg.default)
    const hasOverride = value !== null && value !== undefined && value !== ''
    const displayValue = hasOverride ? value : arg.default

    return (
      <div key={arg.key} className="space-y-1">
        <div className="flex items-center gap-2">
          <label className="text-xs text-white/70 flex-1">
            {fieldLabel(arg.key)}
            {arg.required && <span className="text-rose-400 ml-1">*</span>}
            {arg.help && (
              <span className="block text-white/40 text-[10px] mt-0.5">{arg.help}</span>
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
        ) : inferredType === 'password' ? (
          <ObservePasswordInput
            value={displayValue !== null && displayValue !== undefined ? String(displayValue) : ''}
            onChange={onChange}
            disabled={saving}
            configured={configuredSecrets.includes(arg.key)}
          />
        ) : inferredType === 'textarea' ? (
          <textarea
            value={displayValue !== null && displayValue !== undefined ? String(displayValue) : ''}
            onChange={(e) => onChange(e.target.value || null)}
            disabled={saving}
            rows={4}
            placeholder={'https://example.com\nhttps://other.example.com'}
            className="w-full rounded border border-white/10 bg-white/5 px-2 py-1 text-xs text-white placeholder:text-white/40 disabled:opacity-50 font-mono"
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

  if (loading && hosts.length === 0) {
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
            <QuenyxAiButton size="md" label={t('ai.action.explain')} question={t('opsIntel.q.hosts')} />
            <ObservePageToolbar
              interval={interval}
              onIntervalChange={setInterval}
              secondsAgo={secondsAgo}
              onRefresh={() => {
                setDataRefreshKey((k) => k + 1)
                refreshNow()
              }}
              refreshing={loading || refreshing}
              settingsLabel={canEdit ? t('targets.monitoringSettings') : undefined}
              onSettings={canEdit ? () => setSettingsOpen(true) : undefined}
            />
            {canEdit && (
              <>
                {isDirty ? (
                  <span className="text-xs text-amber-300/90">{t('targets.unsavedChanges')}</span>
                ) : null}
                <button
                  onClick={handleAddHost}
                  disabled={saving}
                  className="rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white/70 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-white/10"
                >
                  {t('targets.addHost')}
                </button>
                <button
                  onClick={() => void handleSave()}
                  disabled={saving || !isDirty}
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
        <div className="mb-4 flex flex-wrap items-center gap-3">
          <label className="text-xs text-white/50" htmlFor="lifecycle-filter">
            Filter:
          </label>
          <select
            id="lifecycle-filter"
            value={lifecycleFilter}
            onChange={(e) => setLifecycleFilter(e.target.value)}
            className="rounded border border-white/15 bg-black/30 px-2 py-1 text-sm text-white"
          >
            <option value="default">Active</option>
            <option value="suspended">Suspended</option>
            <option value="archived">Archived</option>
            <option value="agent_removed">Agent removed</option>
            <option value="all">All visible</option>
          </select>
        </div>
        <div className="overflow-x-auto overflow-y-visible">
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
                      key={host.id ?? `new-${hostIndex}`}
                      className={`border-b border-white/5 hover:bg-white/5 ${!host.id ? 'bg-sky-500/5' : ''}`}
                    >
                      <td className="px-3 py-2.5 font-medium">{host.name || '—'}</td>
                      <td className="px-3 py-2.5 text-white/70">
                        {host.public_ip || host.address || '—'}
                        {host.public_ip && host.address && host.public_ip !== host.address ? (
                          <span className="mt-0.5 block text-[10px] text-white/40">priv {host.address}</span>
                        ) : null}
                      </td>
                      <td className="px-3 py-2.5 text-white/70">{os ?? t('hosts.osUnknown')}</td>
                      <td className={`px-3 py-2.5 uppercase text-xs ${lifecycleStatusClass(host.lifecycle_status ?? runtime?.status)}`}>
                        {['agent_removed', 'monitoring_disabled', 'suspended', 'archived'].includes(host.lifecycle_status ?? '')
                          ? lifecycleStatusLabel(host.lifecycle_status)
                          : host.lifecycle_reason
                            ? lifecycleStatusLabel(host.lifecycle_status)
                            : (runtime?.status ?? t('targets.status.unknown'))}
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
                          {host.uuid && workspaceId ? (
                            <HostLifecycleMenu
                              workspaceId={workspaceId}
                              hostUuid={host.uuid}
                              hostName={host.name}
                              lifecycleStatus={host.lifecycle_status}
                              canEdit={canEdit}
                              onChanged={() => void reloadTargets({ force: true, background: true })}
                            />
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
                  <div className="mt-4 space-y-3 pl-6">
                    <span className="text-xs font-medium text-white/70">{t('targets.servicesSection')}</span>
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
                                  <ObserveServiceTypeSelect
                                    value={displayedServiceKey || ''}
                                    options={definitions}
                                    onChange={(key) => handleUpdateService(hostIndex, serviceIndex, 'service_key', key)}
                                    disabled={saving}
                                    error={
                                      getFieldError(`hosts.${hostIndex}.services.${serviceIndex}.service_key`) ||
                                      getFieldError(`hosts.${hostIndex}.services.${serviceIndex}.check_command`)
                                    }
                                  />
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
                                    <div className="flex flex-wrap items-center justify-end gap-2">
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
                                            placeholder={String(DEFAULT_CHECK_INTERVAL_MIN)}
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
                                            placeholder={String(DEFAULT_RETRY_INTERVAL_MIN)}
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
                                            (val) => handleUpdateOverride(hostIndex, serviceIndex, arg.key, val, isSecretField(arg)),
                                            () => handleClearOverride(hostIndex, serviceIndex, arg.key, isSecretField(arg)),
                                            error,
                                            service.configured_secrets ?? []
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
                    {canEdit ? (
                      <div ref={serviceListEndRef} className="pt-2">
                        <button
                          type="button"
                          onClick={() => handleAddService(hostIndex)}
                          disabled={saving}
                          className="w-full rounded-lg border border-dashed border-sky-500/40 bg-sky-500/5 px-4 py-2.5 text-xs font-medium text-sky-300 transition hover:bg-sky-500/10 disabled:opacity-50"
                        >
                          + {t('targets.addServiceCheck')}
                        </button>
                      </div>
                    ) : null}
                  </div>
                )}
              </div>
          )
        })()}
      </div>
            </div>
            {canEdit ? (
              <div className="flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-white/10 bg-[#0f151d] px-5 py-4">
                <button
                  type="button"
                  onClick={() => setDrawerHostIndex(null)}
                  disabled={saving}
                  className="rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-xs text-white/70 hover:bg-white/10 disabled:opacity-50"
                >
                  {t('common.close')}
                </button>
                <button
                  type="button"
                  onClick={() => void handleSaveFromDrawer(false)}
                  disabled={saving}
                  className="rounded-lg border border-sky-500/30 bg-sky-500/20 px-4 py-2 text-xs font-medium text-sky-200 hover:bg-sky-500/30 disabled:opacity-50"
                >
                  {saving ? t('targets.saving') : t('targets.save')}
                </button>
                <button
                  type="button"
                  onClick={() => void handleSaveFromDrawer(true)}
                  disabled={saving}
                  className="rounded-lg border border-sky-500/50 bg-sky-500/30 px-4 py-2 text-xs font-medium text-sky-100 hover:bg-sky-500/40 disabled:opacity-50"
                >
                  {saving ? t('targets.saving') : t('targets.saveAndClose')}
                </button>
              </div>
            ) : null}
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
