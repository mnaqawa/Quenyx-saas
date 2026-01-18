import { useEffect, useState } from 'react'
import { useProjectContext } from '../projects/ProjectContext'
import { moduleService, AuditLog } from '../services/moduleService'

function ProjectAccessSettings() {
  const { selectedProjectId, modulesWithAccess, isLoadingModules, refreshModules, refreshEntitlements } = useProjectContext()
  const [auditLogs, setAuditLogs] = useState<AuditLog[]>([])
  const [loadingAuditLogs, setLoadingAuditLogs] = useState(false)
  const [overrideError, setOverrideError] = useState<string | null>(null)

  useEffect(() => {
    const fetchAuditLogs = async () => {
      if (!selectedProjectId) {
        setAuditLogs([])
        return
      }
      setLoadingAuditLogs(true)
      try {
        const response = await moduleService.getProjectAuditLogs(selectedProjectId)
        if (response.success) {
          setAuditLogs(response.data)
        }
      } catch (err) {
        // Ignore errors
      } finally {
        setLoadingAuditLogs(false)
      }
    }

    fetchAuditLogs()
  }, [selectedProjectId])

  const handleOverrideChange = async (moduleKey: string, mode: 'allow' | 'deny' | null) => {
    if (!selectedProjectId) return

    setOverrideError(null)
    try {
      const response = await moduleService.updateModuleOverride(selectedProjectId, moduleKey, mode)
      if (!response.success) {
        if (response.message?.includes('403') || response.message?.includes('owners')) {
          setOverrideError('Only project owners can change access settings.')
        } else {
          setOverrideError(response.message || 'Failed to update module override')
        }
        return
      }
      await refreshModules()
      await refreshEntitlements()
      // Refresh audit logs to show the new entry
      const auditResponse = await moduleService.getProjectAuditLogs(selectedProjectId)
      if (auditResponse.success) {
        setAuditLogs(auditResponse.data)
      }
    } catch (err) {
      const errorMessage = err instanceof Error ? err.message : 'Failed to update module override'
      if (errorMessage.includes('403') || errorMessage.includes('owners')) {
        setOverrideError('Only project owners can change access settings.')
      } else {
        setOverrideError(errorMessage)
      }
    }
  }

  if (!selectedProjectId) {
    return (
      <div className="space-y-6">
        <div className="space-y-1 text-center">
          <h1 className="text-2xl font-semibold text-white">Project Access</h1>
          <p className="text-sm text-white/60">Manage module access for your project</p>
        </div>
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-10 text-center text-white">
          <p className="text-sm text-white/60">Select a project to manage access settings</p>
        </div>
      </div>
    )
  }

  if (isLoadingModules) {
    return <div className="text-sm text-white/60">Loading...</div>
  }

  return (
    <div className="space-y-6">
      <div className="space-y-1 text-center">
        <h1 className="text-2xl font-semibold text-white">Project Access</h1>
        <p className="text-sm text-white/60">Manage module access for your project</p>
      </div>

      {overrideError && (
        <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
          {overrideError}
        </div>
      )}

      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
        <p className="mb-4 text-xs text-white/60">
          Plan defines defaults; overrides let you enable/disable modules for this project.
        </p>

        {modulesWithAccess && modulesWithAccess.length > 0 ? (
          <div className="space-y-4">
            {modulesWithAccess.map((module) => (
              <div
                key={module.key}
                className="flex items-center justify-between gap-4 rounded-lg border border-white/5 bg-white/5 p-4"
              >
                <div className="flex-1">
                  <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-full bg-white/5">
                      <span className="text-xs font-semibold text-white/70">
                        {module.name.slice(0, 2)}
                      </span>
                    </div>
                    <div>
                      <h3 className="text-sm font-semibold">{module.name}</h3>
                      <div className="mt-1 flex items-center gap-2">
                        <span className={`text-xs ${module.allowed ? 'text-emerald-200' : 'text-white/50'}`}>
                          {module.allowed ? 'Enabled' : 'Disabled'}
                        </span>
                        {module.allowed_by_plan && !module.override && (
                          <span className="rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2 py-0.5 text-[10px] text-emerald-200">
                            Included (Plan)
                          </span>
                        )}
                        {module.override === 'allow' && (
                          <span className="rounded-full border border-sky-500/30 bg-sky-500/10 px-2 py-0.5 text-[10px] text-sky-200">
                            Override: Enabled
                          </span>
                        )}
                        {module.override === 'deny' && (
                          <span className="rounded-full border border-rose-500/30 bg-rose-500/10 px-2 py-0.5 text-[10px] text-rose-200">
                            Override: Disabled
                          </span>
                        )}
                      </div>
                    </div>
                  </div>
                </div>
                <div className="flex items-center gap-3">
                  <label className="text-xs text-white/60">Override:</label>
                  <select
                    value={module.override || ''}
                    onChange={(e) => {
                      const value = e.target.value
                      handleOverrideChange(module.key, value === '' ? null : value as 'allow' | 'deny')
                    }}
                    className="rounded-md border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white focus:border-sky-500 focus:outline-none"
                  >
                    <option value="">Default (Plan)</option>
                    <option value="allow">Force Enable</option>
                    <option value="deny">Force Disable</option>
                  </select>
                </div>
              </div>
            ))}
          </div>
        ) : (
          <div className="text-sm text-white/60">No modules available</div>
        )}
      </div>

      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
        <h2 className="mb-4 text-sm font-semibold">Recent Changes</h2>
        {loadingAuditLogs ? (
          <div className="text-sm text-white/60">Loading audit logs...</div>
        ) : auditLogs.length > 0 ? (
          <div className="space-y-3">
            {auditLogs
              .filter((log) => log.action === 'module_override_updated')
              .map((log) => (
                <div
                  key={log.id}
                  className="rounded-lg border border-white/5 bg-white/5 p-3 text-xs"
                >
                  <div className="flex items-center justify-between">
                    <div>
                      <span className="text-white/60">
                        {new Date(log.timestamp).toLocaleString()}
                      </span>
                      {log.user && (
                        <span className="ml-2 text-white/40">
                          by {log.user.name} ({log.user.email})
                        </span>
                      )}
                    </div>
                  </div>
                  {log.metadata.module_key && (
                    <div className="mt-2 text-white/80">
                      <span className="font-semibold">{log.metadata.module_name || log.metadata.module_key}</span>
                      {' → '}
                      <span className="text-white/60">
                        {log.metadata.old_mode === null ? 'Default' : log.metadata.old_mode}
                      </span>
                      {' → '}
                      <span className={log.metadata.new_mode === 'allow' ? 'text-emerald-200' : log.metadata.new_mode === 'deny' ? 'text-rose-200' : 'text-white/60'}>
                        {log.metadata.new_mode === null ? 'Default' : log.metadata.new_mode}
                      </span>
                    </div>
                  )}
                </div>
              ))}
            {auditLogs.filter((log) => log.action === 'module_override_updated').length === 0 && (
              <div className="text-sm text-white/60">No module override changes yet</div>
            )}
          </div>
        ) : (
          <div className="text-sm text-white/60">No audit logs available</div>
        )}
      </div>
    </div>
  )
}

export default ProjectAccessSettings
