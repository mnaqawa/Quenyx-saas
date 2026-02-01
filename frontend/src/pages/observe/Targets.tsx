import { useState, useEffect, useMemo } from 'react'
import { useParams } from 'react-router-dom'
import { useWorkspaceContext } from '../../workspaces/WorkspaceContext'
import { PageHeader } from '../../components/observe/PageHeader'
import { gatewayClient } from '../../services/gatewayClient'
import { observeService } from '../../services/observeService'
import type { ServiceDefinition, ArgsSchemaEntry } from '../../types/observe'

interface TargetHost {
  id?: number
  name: string
  address: string
  check_command: string
  tags: string[]
  enabled: boolean
  services: TargetService[]
}

interface TargetService {
  id?: number
  name: string
  check_command?: string
  check_args?: unknown[]
  service_key?: string
  overrides?: Record<string, unknown>
  enabled: boolean
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

/** Ensure each service has service_key set from check_command when missing (so dropdown displays correctly). */
function normalizeHostsServiceKeys(hosts: TargetHost[]): TargetHost[] {
  return hosts.map((host) => ({
    ...host,
    services: host.services.map((svc) => {
      const key = svc.service_key || inferServiceKeyFromCheckCommand(svc.check_command)
      return key ? { ...svc, service_key: key } : svc
    }),
  }))
}

/**
 * Merge PUT response with current state so we never lose service_key or overrides
 * when the API omits them (avoids config "disappearing" after save).
 */
function mergeResponseWithCurrent(
  currentHosts: TargetHost[],
  responseHosts: TargetHost[]
): TargetHost[] {
  return responseHosts.map((host, hi) => ({
    ...host,
    services: host.services.map((svc, si) => {
      const currentSvc = currentHosts[hi]?.services[si]
      const serviceKey =
        svc.service_key ||
        currentSvc?.service_key ||
        inferServiceKeyFromCheckCommand(svc.check_command ?? currentSvc?.check_command)
      const overrides =
        svc.overrides && Object.keys(svc.overrides).length > 0
          ? svc.overrides
          : (currentSvc?.overrides ?? {})
      return {
        ...svc,
        service_key: serviceKey || svc.service_key,
        overrides,
      }
    }),
  }))
}

export default function Targets() {
  const { id } = useParams<{ id: string }>()
  const { selectedWorkspaceId, modulesWithAccess, allowedByKey } = useWorkspaceContext()
  const [hosts, setHosts] = useState<TargetHost[]>([])
  const [definitions, setDefinitions] = useState<ServiceDefinition[]>([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState<string | null>(null)
  const [validationErrors, setValidationErrors] = useState<Record<string, string[]>>({})
  const [expandedHosts, setExpandedHosts] = useState<Set<number | string>>(new Set())
  const [expandedServices, setExpandedServices] = useState<Set<string>>(new Set())

  const workspaceId = id || selectedWorkspaceId

  const isLocked = modulesWithAccess?.find((m) => m.key === 'shieldobserve')
    ? !allowedByKey['shieldobserve']
    : false

  const canEdit = !isLocked && (allowedByKey['shieldobserve'] ?? false)

  const definitionsByKey = useMemo(() => {
    const map = new Map<string, ServiceDefinition>()
    definitions.forEach((d) => map.set(d.service_key, d))
    return map
  }, [definitions])

  useEffect(() => {
    if (!workspaceId) return

    const fetchData = async () => {
      try {
        setLoading(true)
        setError(null)
        const [targetsResponse, defsResponse] = await Promise.all([
          gatewayClient.get<TargetHost[]>(`workspaces/${workspaceId}/observe/targets`, {
            workspaceId: String(workspaceId),
            moduleKey: 'shieldobserve',
          }),
          observeService.getServiceDefinitions(Number(workspaceId), { engine: 'nagios', status: 'active' }),
        ])
        if (Array.isArray(targetsResponse)) {
          setHosts(normalizeHostsServiceKeys(targetsResponse))
        }
        if (Array.isArray(defsResponse)) {
          setDefinitions(defsResponse)
          const cacheKey = `observe_defs_${workspaceId}`
          try {
            localStorage.setItem(cacheKey, JSON.stringify({ data: defsResponse, version: '1.0', timestamp: Date.now() }))
          } catch {}
        }
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load targets')
      } finally {
        setLoading(false)
      }
    }

    fetchData()
  }, [workspaceId])

  const handleAddHost = () => {
    setHosts([
      ...hosts,
      {
        name: '',
        address: '',
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
    ;(newHosts[hostIndex] as any)[field] = value
    setHosts(newHosts)
  }

  const handleUpdateService = (
    hostIndex: number,
    serviceIndex: number,
    field: keyof TargetService,
    value: unknown
  ) => {
    const newHosts = [...hosts]
    ;(newHosts[hostIndex].services[serviceIndex] as any)[field] = value
    if (field === 'service_key' && typeof value === 'string') {
      const def = definitionsByKey.get(value)
      if (def) {
        const defaults: Record<string, unknown> = {}
        def.args_schema.forEach((arg) => {
          if (arg.default !== null && arg.default !== undefined) {
            defaults[arg.key] = arg.default
          }
        })
        newHosts[hostIndex].services[serviceIndex].overrides = defaults
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
    const overrides = { ...(service.overrides || {}) }
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
      handleUpdateService(hostIndex, serviceIndex, 'overrides', defaults)
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
        errors[`hosts.${hi}.name`] = ['Host name and address are required']
      }
      for (let si = 0; si < host.services.length; si++) {
        const service = host.services[si]
        if (!service.name.trim()) {
          errors[`hosts.${hi}.services.${si}.name`] = ['Service name is required']
        }
        const effectiveServiceKey = service.service_key || inferServiceKeyFromCheckCommand(service.check_command)
        if (!effectiveServiceKey) {
          errors[`hosts.${hi}.services.${si}.service_key`] = ['Service type is required']
        } else {
          const def = definitionsByKey.get(effectiveServiceKey)
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
                if (effectiveServiceKey === 'ping' && (arg.key === 'warn_rta_ms' || arg.key === 'crit_rta_ms')) {
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
          check_command: host.check_command,
          tags: host.tags,
          enabled: host.enabled,
          services: host.services.map((service) => {
            const effectiveKey = service.service_key || inferServiceKeyFromCheckCommand(service.check_command)
            return {
              name: service.name,
              service_key: effectiveKey || undefined,
              check_command: effectiveKey ? undefined : (service.check_command || undefined),
              overrides: service.overrides || {},
              enabled: service.enabled,
            }
          }),
        })),
      }

      const updatedHosts = await gatewayClient.put<TargetHost[]>(
        `workspaces/${workspaceId}/observe/targets`,
        payload,
        { workspaceId: String(workspaceId), moduleKey: 'shieldobserve' }
      )

      setSuccess('Targets saved and published to Nagios')
      // Merge response with current state so service_key and overrides never disappear after save
      if (Array.isArray(updatedHosts)) {
        const merged = mergeResponseWithCurrent(hosts, updatedHosts)
        setHosts(normalizeHostsServiceKeys(merged))
      }
    } catch (err: any) {
      const fieldErrors = err?.errors ?? err?.response?.data?.errors
      if (fieldErrors && typeof fieldErrors === 'object') {
        setValidationErrors(fieldErrors)
        setError('Validation failed. Please fix the highlighted fields.')
      } else {
        setError(err instanceof Error ? err.message : 'Failed to save targets')
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
        <div className="text-sm text-white/60">Loading targets...</div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      {isLocked && (
        <div className="rounded-lg border border-yellow-500/30 bg-yellow-500/10 px-4 py-2 text-sm text-yellow-200">
          <div className="flex items-center gap-2">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
              <path d="M7 11V7a5 5 0 0 1 10 0v4" />
            </svg>
            <span>ShieldObserve is locked. Some features are disabled.</span>
          </div>
        </div>
      )}

      <PageHeader
        title="Monitored Targets"
        subtitle="Define hosts and services to monitor in Nagios"
        actions={
          <>
            {canEdit && (
              <>
                <button
                  onClick={handleAddHost}
                  disabled={saving}
                  className="rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white/70 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-white/10"
                >
                  Add Host
                </button>
                <button
                  onClick={handleSave}
                  disabled={saving || isLocked}
                  className="rounded-lg border border-sky-500/30 bg-sky-500/20 px-4 py-1.5 text-xs font-medium text-sky-200 disabled:opacity-50 disabled:cursor-not-allowed hover:bg-sky-500/30"
                >
                  {saving ? 'Saving...' : 'Save & Publish'}
                </button>
              </>
            )}
          </>
        }
      />

      {error && (
        <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
          {error}
        </div>
      )}

      {success && (
        <div className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
          {success}
        </div>
      )}

      <div className="space-y-4">
        {hosts.length === 0 ? (
          <div className="rounded-lg border border-white/10 bg-white/5 p-8 text-center text-sm text-white/60">
            No targets defined. Click "Add Host" to get started.
          </div>
        ) : (
          hosts.map((host, hostIndex) => {
            const hostKey = host.id ?? `new-${hostIndex}`
            return (
              <div key={hostKey} className="rounded-lg border border-white/10 bg-white/5 p-4">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-3 flex-1">
                    <button
                      onClick={() => toggleHostExpanded(hostKey)}
                      className="text-white/60 hover:text-white transition"
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
                      <>
                        <input
                          type="text"
                          placeholder="Host name"
                          value={host.name}
                          onChange={(e) => handleUpdateHost(hostIndex, 'name', e.target.value)}
                          disabled={saving}
                          className={`flex-1 rounded-lg border px-3 py-1.5 text-xs text-white placeholder:text-white/40 disabled:opacity-50 ${
                            getFieldError(`hosts.${hostIndex}.name`)
                              ? 'border-rose-500/50 bg-rose-500/10'
                              : 'border-white/10 bg-white/5'
                          }`}
                        />
                        <input
                          type="text"
                          placeholder="Address (IP/hostname)"
                          value={host.address}
                          onChange={(e) => handleUpdateHost(hostIndex, 'address', e.target.value)}
                          disabled={saving}
                          className={`flex-1 rounded-lg border px-3 py-1.5 text-xs text-white placeholder:text-white/40 disabled:opacity-50 ${
                            getFieldError(`hosts.${hostIndex}.address`)
                              ? 'border-rose-500/50 bg-rose-500/10'
                              : 'border-white/10 bg-white/5'
                          }`}
                        />
                        <input
                          type="text"
                          placeholder="Check command"
                          value={host.check_command}
                          onChange={(e) => handleUpdateHost(hostIndex, 'check_command', e.target.value)}
                          disabled={saving}
                          className="w-48 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white placeholder:text-white/40 disabled:opacity-50"
                        />
                      </>
                    ) : (
                      <>
                        <span className="text-sm font-medium text-white">{host.name}</span>
                        <span className="text-xs text-white/60">{host.address}</span>
                        <span className="text-xs text-white/50">{host.check_command}</span>
                      </>
                    )}
                    <label className="flex items-center gap-2 text-xs text-white/70">
                      <input
                        type="checkbox"
                        checked={host.enabled}
                        onChange={(e) => handleUpdateHost(hostIndex, 'enabled', e.target.checked)}
                        disabled={saving || !canEdit}
                        className="rounded border-white/20"
                      />
                      Enabled
                    </label>
                    {canEdit && (
                      <button
                        onClick={() => handleRemoveHost(hostIndex)}
                        disabled={saving}
                        className="text-rose-400 hover:text-rose-300 text-xs disabled:opacity-50"
                      >
                        Remove
                      </button>
                    )}
                  </div>
                </div>

                {expandedHosts.has(hostKey) && (
                  <div className="mt-4 space-y-2 pl-6">
                    <div className="flex items-center justify-between mb-2">
                      <span className="text-xs font-medium text-white/70">Services</span>
                      {canEdit && (
                        <button
                          onClick={() => handleAddService(hostIndex)}
                          disabled={saving}
                          className="text-xs text-sky-400 hover:text-sky-300 disabled:opacity-50"
                        >
                          + Add Service
                        </button>
                      )}
                    </div>
                    {host.services.length === 0 ? (
                      <div className="text-xs text-white/50">No services defined</div>
                    ) : (
                      host.services.map((service, serviceIndex) => {
                        const serviceKey = `${hostIndex}-${serviceIndex}`
                        const displayedServiceKey = service.service_key || inferServiceKeyFromCheckCommand(service.check_command)
                        const def = displayedServiceKey ? definitionsByKey.get(displayedServiceKey) : null
                        const groups = def ? groupFieldsByCapability(def) : {}
                        return (
                          <div key={serviceIndex} className="rounded border border-white/5 bg-white/5 p-3 space-y-3">
                            <div className="flex items-center gap-2">
                              {canEdit ? (
                                <>
                                  <input
                                    type="text"
                                    placeholder="Service name"
                                    value={service.name}
                                    onChange={(e) => handleUpdateService(hostIndex, serviceIndex, 'name', e.target.value)}
                                    disabled={saving}
                                    className={`flex-1 rounded border px-2 py-1 text-xs text-white placeholder:text-white/40 disabled:opacity-50 ${
                                      getFieldError(`hosts.${hostIndex}.services.${serviceIndex}.name`)
                                        ? 'border-rose-500/50 bg-rose-500/10'
                                        : 'border-white/10 bg-white/5'
                                    }`}
                                  />
                                  <select
                                    value={displayedServiceKey || ''}
                                    onChange={(e) => handleUpdateService(hostIndex, serviceIndex, 'service_key', e.target.value)}
                                    disabled={saving}
                                    className={`flex-1 rounded border px-2 py-1 text-xs text-white disabled:opacity-50 ${
                                      getFieldError(`hosts.${hostIndex}.services.${serviceIndex}.service_key`) ||
                                      getFieldError(`hosts.${hostIndex}.services.${serviceIndex}.check_command`)
                                        ? 'border-rose-500/50 bg-rose-500/10'
                                        : 'border-white/10 bg-white/5'
                                    }`}
                                  >
                                    <option value="">Select service type...</option>
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
                                Enabled
                              </label>
                              {canEdit && (
                                <button
                                  onClick={() => handleRemoveService(hostIndex, serviceIndex)}
                                  disabled={saving}
                                  className="text-rose-400 hover:text-rose-300 text-xs disabled:opacity-50"
                                >
                                  Remove
                                </button>
                              )}
                            </div>
                            {def && displayedServiceKey && (
                              <>
                                <button
                                  type="button"
                                  onClick={() => toggleServiceExpanded(serviceKey)}
                                  className="text-xs text-sky-400 hover:text-sky-300"
                                >
                                  {expandedServices.has(serviceKey) ? '▼' : '▶'} Configuration
                                </button>
                                {expandedServices.has(serviceKey) && (
                                  <div className="space-y-4 pl-4 border-l border-white/10">
                                    <div className="flex items-center justify-between">
                                      <span className="text-xs font-medium text-white/70">Service Configuration</span>
                                      <button
                                        type="button"
                                        onClick={() => handleResetToDefaults(hostIndex, serviceIndex)}
                                        className="text-xs text-sky-400 hover:text-sky-300"
                                      >
                                        Reset to defaults
                                      </button>
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
          })
        )}
      </div>
    </div>
  )
}
