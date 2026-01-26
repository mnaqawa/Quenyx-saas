import { useState, useEffect } from 'react'
import { useParams } from 'react-router-dom'
import { useWorkspaceContext } from '../../workspaces/WorkspaceContext'
import { PageHeader } from '../../components/observe/PageHeader'
import { gatewayClient } from '../../services/gatewayClient'

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
  check_command: string
  check_args: string[]
  enabled: boolean
}

export default function Targets() {
  const { id } = useParams<{ id: string }>()
  const { selectedWorkspaceId, modulesWithAccess, allowedByKey } = useWorkspaceContext()
  const [hosts, setHosts] = useState<TargetHost[]>([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState<string | null>(null)
  const [expandedHosts, setExpandedHosts] = useState<Set<number | string>>(new Set())

  const workspaceId = id || selectedWorkspaceId

  const isLocked = modulesWithAccess?.find((m) => m.key === 'shieldobserve')
    ? !allowedByKey['shieldobserve']
    : false

  // Can edit if module is unlocked (admin/owner can edit, member/viewer can view)
  // For now, we'll allow edit if module is accessible (simplified - can be enhanced with role checks)
  const canEdit = !isLocked && (allowedByKey['shieldobserve'] ?? false)

  useEffect(() => {
    if (!workspaceId) return

    const fetchTargets = async () => {
      try {
        setLoading(true)
        setError(null)
        const response = await gatewayClient.get<TargetHost[]>(
          `workspaces/${workspaceId}/observe/targets`,
          { workspaceId: String(workspaceId), moduleKey: 'shieldobserve' }
        )
        // apiClient unwraps { success, data } -> returns data directly
        if (Array.isArray(response)) {
          setHosts(response)
        }
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load targets')
      } finally {
        setLoading(false)
      }
    }

    fetchTargets()
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
        check_command: 'check_ping',
        check_args: [],
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

  const handleUpdateHost = (hostIndex: number, field: keyof TargetHost, value: any) => {
    const newHosts = [...hosts]
    ;(newHosts[hostIndex] as any)[field] = value
    setHosts(newHosts)
  }

  const handleUpdateService = (
    hostIndex: number,
    serviceIndex: number,
    field: keyof TargetService,
    value: any
  ) => {
    const newHosts = [...hosts]
    ;(newHosts[hostIndex].services[serviceIndex] as any)[field] = value
    setHosts(newHosts)
  }

  const handleSave = async () => {
    if (!workspaceId) return

    // Validate
    for (const host of hosts) {
      if (!host.name.trim() || !host.address.trim()) {
        setError('All hosts must have a name and address')
        return
      }
      for (const service of host.services) {
        if (!service.name.trim() || !service.check_command.trim()) {
          setError('All services must have a name and check command')
          return
        }
      }
    }

    try {
      setSaving(true)
      setError(null)
      setSuccess(null)

      await gatewayClient.put<{ message?: string }>(
        `workspaces/${workspaceId}/observe/targets`,
        { hosts },
        { workspaceId: String(workspaceId), moduleKey: 'shieldobserve' }
      )

      setSuccess('Targets saved and published to Nagios')
      // Refresh data
      const refreshResponse = await gatewayClient.get<TargetHost[]>(
        `workspaces/${workspaceId}/observe/targets`,
        { workspaceId: String(workspaceId), moduleKey: 'shieldobserve' }
      )
      if (Array.isArray(refreshResponse)) {
        setHosts(refreshResponse)
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save targets')
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
                        className="flex-1 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white placeholder:text-white/40 disabled:opacity-50"
                      />
                      <input
                        type="text"
                        placeholder="Address (IP/hostname)"
                        value={host.address}
                        onChange={(e) => handleUpdateHost(hostIndex, 'address', e.target.value)}
                        disabled={saving}
                        className="flex-1 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white placeholder:text-white/40 disabled:opacity-50"
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
                    host.services.map((service, serviceIndex) => (
                      <div key={serviceIndex} className="flex items-center gap-2 rounded border border-white/5 bg-white/5 p-2">
                        {canEdit ? (
                          <>
                            <input
                              type="text"
                              placeholder="Service name"
                              value={service.name}
                              onChange={(e) => handleUpdateService(hostIndex, serviceIndex, 'name', e.target.value)}
                              disabled={saving}
                              className="flex-1 rounded border border-white/10 bg-white/5 px-2 py-1 text-xs text-white placeholder:text-white/40 disabled:opacity-50"
                            />
                            <input
                              type="text"
                              placeholder="Check command"
                              value={service.check_command}
                              onChange={(e) =>
                                handleUpdateService(hostIndex, serviceIndex, 'check_command', e.target.value)
                              }
                              disabled={saving}
                              className="flex-1 rounded border border-white/10 bg-white/5 px-2 py-1 text-xs text-white placeholder:text-white/40 disabled:opacity-50"
                            />
                          </>
                        ) : (
                          <>
                            <span className="flex-1 text-xs text-white">{service.name}</span>
                            <span className="flex-1 text-xs text-white/60">{service.check_command}</span>
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
                    ))
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
