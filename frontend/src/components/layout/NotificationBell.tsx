import { useCallback, useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useLanguage } from '../../i18n/LanguageContext'
import { useWorkspaceContext } from '../../workspaces/WorkspaceContext'
import { notifyService } from '../../services/notifyService'
import type { NotificationSummary } from '../../types/notify'
import { IconNotifications } from '../icons/ModuleIcons'

export function NotificationBell() {
  const { t } = useLanguage()
  const { selectedWorkspace } = useWorkspaceContext()
  const uuid = selectedWorkspace?.uuid
  const [open, setOpen] = useState(false)
  const [items, setItems] = useState<NotificationSummary[]>([])
  const [loading, setLoading] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)

  const load = useCallback(async () => {
    if (!uuid) {
      setItems([])
      return
    }
    setLoading(true)
    try {
      const res = await notifyService.list(uuid, { status: 'unread' })
      setItems((res.notifications ?? []).slice(0, 8))
    } catch {
      setItems([])
    } finally {
      setLoading(false)
    }
  }, [uuid])

  useEffect(() => {
    void load()
  }, [load])

  useEffect(() => {
    if (!open) return
    void load()
    const onDoc = (e: MouseEvent) => {
      if (rootRef.current && !rootRef.current.contains(e.target as Node)) setOpen(false)
    }
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', onDoc)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDoc)
      document.removeEventListener('keydown', onKey)
    }
  }, [load, open])

  const unread = items.length
  const notifyPath = selectedWorkspace?.id ? `/app/workspaces/${selectedWorkspace.id}/qynnotify` : null

  return (
    <div ref={rootRef} className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-label={t('notifications.bell')}
        aria-expanded={open}
        className="relative inline-flex items-center justify-center rounded-lg border border-white/10 p-2 text-white/70 transition hover:bg-white/10 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-500"
      >
        <IconNotifications size={16} />
        {unread > 0 ? (
          <span className="absolute -end-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-orange-500 px-1 text-[9px] font-bold text-white">
            {unread > 9 ? '9+' : unread}
          </span>
        ) : null}
      </button>

      {open ? (
        <div className="absolute end-0 z-50 mt-2 w-80 overflow-hidden rounded-xl border border-white/10 bg-[#161c24] shadow-2xl shadow-black/50">
          <div className="flex items-center justify-between border-b border-white/10 px-3 py-2">
            <span className="text-xs font-semibold text-white">{t('notifications.title')}</span>
            {notifyPath ? (
              <Link to={notifyPath} onClick={() => setOpen(false)} className="text-[10px] text-sky-300 hover:text-sky-200">
                {t('notifications.viewAll')}
              </Link>
            ) : null}
          </div>
          <div className="max-h-72 overflow-y-auto">
            {loading ? (
              <p className="px-3 py-4 text-xs text-white/45">{t('common.loading')}</p>
            ) : !uuid ? (
              <p className="px-3 py-4 text-xs text-white/45">{t('notifications.selectWorkspace')}</p>
            ) : items.length === 0 ? (
              <p className="px-3 py-4 text-xs text-white/45">{t('notifications.empty')}</p>
            ) : (
              <ul>
                {items.map((n) => (
                  <li key={n.uuid} className="border-b border-white/5 px-3 py-2.5 last:border-0">
                    <p className="truncate text-xs font-medium text-white">{n.title}</p>
                    <p className="mt-0.5 line-clamp-2 text-[10px] text-white/45">{n.source}</p>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      ) : null}
    </div>
  )
}
