import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
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
  const buttonRef = useRef<HTMLButtonElement>(null)
  const menuRef = useRef<HTMLDivElement>(null)
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [menuPosition, setMenuPosition] = useState<{ top: number; right: number } | null>(null)

  const isBlocked = BLOCKED.includes(lifecycleStatus as HostLifecycleStatus)

  useLayoutEffect(() => {
    if (!open || !buttonRef.current) {
      setMenuPosition(null)
      return
    }

    const updatePosition = () => {
      const rect = buttonRef.current?.getBoundingClientRect()
      if (!rect) return
      setMenuPosition({
        top: rect.bottom + 4,
        right: window.innerWidth - rect.right,
      })
    }

    updatePosition()
    window.addEventListener('resize', updatePosition)
    window.addEventListener('scroll', updatePosition, true)

    return () => {
      window.removeEventListener('resize', updatePosition)
      window.removeEventListener('scroll', updatePosition, true)
    }
  }, [open])

  useEffect(() => {
    if (!open) return

    const onPointerDown = (event: MouseEvent) => {
      const target = event.target as Node
      if (buttonRef.current?.contains(target) || menuRef.current?.contains(target)) {
        return
      }
      setOpen(false)
    }

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setOpen(false)
      }
    }

    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)

    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [open])

  if (!canEdit || !hostUuid) return null

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

  const menu = open && menuPosition
    ? createPortal(
        <div
          ref={menuRef}
          role="menu"
          className="fixed z-[2000] min-w-[200px] rounded-lg border border-white/10 bg-[#161c24] py-1 shadow-2xl ring-1 ring-black/40"
          style={{ top: menuPosition.top, right: menuPosition.right }}
        >
          {!isBlocked ? (
            <>
              <button
                type="button"
                role="menuitem"
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
                role="menuitem"
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
                role="menuitem"
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
              role="menuitem"
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
            role="menuitem"
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
        </div>,
        document.body,
      )
    : null

  return (
    <div className="relative inline-block text-start">
      <button
        ref={buttonRef}
        type="button"
        disabled={busy}
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen((value) => !value)}
        className="rounded border border-white/15 bg-white/5 px-2 py-1 text-xs text-white/80 hover:bg-white/10"
      >
        Actions ▾
      </button>
      {menu}
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
