import { useState } from 'react'
import { hostLifecycleService, type HostLifecycleStatus } from '../../services/hostLifecycleService'

interface HostLifecycleMenuProps {
  workspaceId: string | number
  hostUuid: string
  hostName: string
  lifecycleStatus?: HostLifecycleStatus | string
  canEdit: boolean
  onChanged: () => void
}

const BLOCKED: HostLifecycleStatus[] = ['suspended', 'archived', 'agent_removed', 'monitoring_disabled', 'deleted']

export function HostLifecycleMenu({
  workspaceId,
  hostUuid,
  hostName,
  lifecycleStatus = 'active',
  canEdit,
  onChanged,
}: HostLifecycleMenuProps) {
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  if (!canEdit || !hostUuid) return null

  const isBlocked = BLOCKED.includes(lifecycleStatus as HostLifecycleStatus)

  const run = async (action: () => Promise<unknown>, confirmMsg: string) => {
    if (!window.confirm(confirmMsg)) return
    setBusy(true)
    setError(null)
    try {
      await action()
      setOpen(false)
      onChanged()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Action failed')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="relative inline-block text-start">
      <button
        type="button"
        disabled={busy}
        onClick={() => setOpen((v) => !v)}
        className="rounded border border-white/15 bg-white/5 px-2 py-1 text-xs text-white/80 hover:bg-white/10"
      >
        Actions ▾
      </button>
      {open ? (
        <div className="absolute end-0 z-20 mt-1 min-w-[200px] rounded-lg border border-white/10 bg-[#161c24] py-1 shadow-xl">
          {!isBlocked ? (
            <>
              <button
                type="button"
                className="block w-full px-3 py-2 text-start text-xs text-white/80 hover:bg-white/10"
                onClick={() =>
                  run(
                    () => hostLifecycleService.disableMonitoring(workspaceId, hostUuid),
                    `Disable monitoring for "${hostName}"? Service checks will stop. History is preserved.`
                  )
                }
              >
                Disable monitoring
              </button>
              <button
                type="button"
                className="block w-full px-3 py-2 text-start text-xs text-white/80 hover:bg-white/10"
                onClick={() =>
                  run(
                    () => hostLifecycleService.suspend(workspaceId, hostUuid),
                    `Suspend "${hostName}"? Checks stop until re-enabled.`
                  )
                }
              >
                Suspend
              </button>
              <button
                type="button"
                className="block w-full px-3 py-2 text-start text-xs text-white/80 hover:bg-white/10"
                onClick={() =>
                  run(
                    () => hostLifecycleService.archive(workspaceId, hostUuid),
                    `Archive "${hostName}"? It will be hidden from the default host list.`
                  )
                }
              >
                Archive
              </button>
            </>
          ) : (
            <button
              type="button"
              className="block w-full px-3 py-2 text-start text-xs text-emerald-300 hover:bg-white/10"
              onClick={() =>
                run(
                  () => hostLifecycleService.restore(workspaceId, hostUuid),
                  `Re-enable monitoring for "${hostName}"?`
                )
              }
            >
              Re-enable monitoring
            </button>
          )}
          <button
            type="button"
            className="block w-full px-3 py-2 text-start text-xs text-rose-300 hover:bg-rose-500/10"
            onClick={() =>
              run(
                () => hostLifecycleService.delete(workspaceId, hostUuid, false),
                `Delete "${hostName}"? Soft-delete if history exists; otherwise removed from active lists.`
              )
            }
          >
            Delete host
          </button>
        </div>
      ) : null}
      {error ? <p className="mt-1 text-[10px] text-rose-300">{error}</p> : null}
    </div>
  )
}

export function lifecycleStatusLabel(status?: string): string {
  if (!status) return 'Unknown'
  return status.replace(/_/g, ' ')
}

export function lifecycleStatusClass(status?: string): string {
  switch (status) {
    case 'online':
    case 'active':
      return 'text-emerald-300'
    case 'warning':
      return 'text-amber-300'
    case 'critical':
    case 'offline':
      return 'text-rose-300'
    case 'agent_removed':
    case 'monitoring_disabled':
    case 'suspended':
      return 'text-orange-300'
    case 'archived':
      return 'text-white/40'
    default:
      return 'text-white/60'
  }
}
