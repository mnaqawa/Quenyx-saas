import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useLanguage } from '../../i18n/LanguageContext'
import { useWorkspaceContext } from '../../workspaces/WorkspaceContext'
import { useObserveServices } from '../../hooks/useObserveData'

function statusBadgeClass(status: string): string {
  switch (status) {
    case 'active':
      return 'bg-emerald-500/20 text-emerald-200'
    case 'paused':
      return 'bg-amber-500/20 text-amber-200'
    case 'archived':
      return 'bg-white/10 text-white/50'
    default:
      return 'bg-white/10 text-white/60'
  }
}

function healthFromObserve(hostCount: number, problems: number): { label: string; className: string } {
  if (hostCount === 0) return { label: 'No hosts', className: 'text-white/40' }
  if (problems > 0) return { label: 'Issues detected', className: 'text-amber-300' }
  return { label: 'Healthy', className: 'text-emerald-300' }
}

export function WorkspaceSelector() {
  const { t } = useLanguage()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)
  const {
    workspaces,
    selectedWorkspaceId,
    setSelectedWorkspaceId,
    selectedWorkspace,
    selectedWorkspaceRole,
    workspaceRolesById,
    isLoadingWorkspaces,
    workspacesError,
    allowedByKey,
  } = useWorkspaceContext()

  const hasObserve = !!selectedWorkspaceId && !!allowedByKey.qynsight
  const { data: observeData } = useObserveServices({
    workspaceId: hasObserve ? selectedWorkspaceId : null,
    limit: 200,
    realDataOnly: true,
  })

  const hostCount = useMemo(() => {
    const ht = observeData?.hostTotals
    if (!ht) return 0
    return ht.ok + ht.warning + ht.critical + ht.unknown + ht.pending
  }, [observeData?.hostTotals])

  const problemCount = useMemo(() => {
    const st = observeData?.serviceTotals
    if (!st) return 0
    return st.warning + st.critical + st.unknown
  }, [observeData?.serviceTotals])

  const health = healthFromObserve(hostCount, problemCount)

  useEffect(() => {
    if (!open) return
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
  }, [open])

  const selectWorkspace = (id: string) => {
    setSelectedWorkspaceId(id)
    setOpen(false)
    navigate('/dashboard')
  }

  if (isLoadingWorkspaces) {
    return <span className="text-xs text-white/50">{t('workspaceSelector.loading')}</span>
  }

  if (workspacesError && !workspacesError.includes('Unauthenticated')) {
    return <span className="text-xs text-rose-300">{t('workspaceSelector.error')}</span>
  }

  if (workspaces.length === 0) {
    return <span className="text-xs text-white/50">{t('workspaceSelector.none')}</span>
  }

  const roleLabel = selectedWorkspaceRole ? t(`workspace.role.${selectedWorkspaceRole}`) : '—'
  const envLabel = selectedWorkspace?.status ? t(`workspace.env.${selectedWorkspace.status}`) : '—'

  return (
    <div ref={rootRef} className="relative" data-tour="tour-workspace">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-haspopup="listbox"
        aria-expanded={open}
        className="flex min-w-[12rem] max-w-[20rem] items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-start text-xs transition hover:border-white/20 hover:bg-white/10 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-500"
      >
        <span className="min-w-0 flex-1">
          <span className="block truncate font-medium text-white">{selectedWorkspace?.name ?? t('workspaceSelector.select')}</span>
          <span className="mt-0.5 flex flex-wrap items-center gap-1.5 text-[10px] text-white/45">
            <span className={statusBadgeClass(selectedWorkspace?.status ?? '') + ' rounded px-1 py-0.5'}>{envLabel}</span>
            <span>{roleLabel}</span>
            {selectedWorkspaceId ? (
              <>
                <span aria-hidden>·</span>
                <span className={health.className}>{health.label}</span>
              </>
            ) : null}
          </span>
        </span>
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="shrink-0 text-white/40">
          <polyline points="6 9 12 15 18 9" />
        </svg>
      </button>

      {open ? (
        <div
          role="listbox"
          className="absolute end-0 z-50 mt-2 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-xl border border-white/10 bg-[#161c24] shadow-2xl shadow-black/50"
        >
          <div className="border-b border-white/10 px-3 py-2 text-[10px] font-semibold uppercase tracking-wider text-white/40">
            {t('workspaceSelector.title')}
          </div>
          <ul className="max-h-72 overflow-y-auto py-1">
            {workspaces.map((workspace) => {
              const id = String(workspace.id)
              const role = workspaceRolesById[id]
              const isSelected = id === String(selectedWorkspaceId)
              return (
                <li key={workspace.id}>
                  <button
                    type="button"
                    role="option"
                    aria-selected={isSelected}
                    onClick={() => selectWorkspace(id)}
                    className={[
                      'flex w-full flex-col gap-1 px-3 py-2.5 text-start text-xs transition hover:bg-white/10',
                      isSelected ? 'bg-white/5' : '',
                    ].join(' ')}
                  >
                    <span className="flex items-center justify-between gap-2">
                      <span className="truncate font-medium text-white">{workspace.name}</span>
                      <span className={`shrink-0 rounded px-1.5 py-0.5 text-[10px] ${statusBadgeClass(workspace.status)}`}>
                        {t(`workspace.env.${workspace.status}`)}
                      </span>
                    </span>
                    <span className="flex flex-wrap gap-x-2 gap-y-0.5 text-[10px] text-white/45">
                      <span>{role ? t(`workspace.role.${role}`) : '—'}</span>
                      <span aria-hidden>·</span>
                      <span>{t('workspaceSelector.region')}: —</span>
                      {isSelected && selectedWorkspaceId ? (
                        <>
                          <span aria-hidden>·</span>
                          <span>
                            {t('workspaceSelector.hosts')}: {hostCount}
                          </span>
                        </>
                      ) : null}
                    </span>
                  </button>
                </li>
              )
            })}
          </ul>
        </div>
      ) : null}
    </div>
  )
}
