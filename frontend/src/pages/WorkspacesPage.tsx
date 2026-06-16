import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useLocation } from 'react-router-dom'
import { workspaceService } from '../services/workspaceService'
import { observeService } from '../services/observeService'
import { ProjectStatus } from '../types/project'
import { WorkspaceListItem, Role } from '../types/workspace'
import { useLanguage } from '../i18n/LanguageContext'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'

const statusOptions: ProjectStatus[] = ['active', 'paused', 'archived']

const statusDotClass = (status: string): string => {
  switch (status) {
    case 'active':
      return 'bg-emerald-400'
    case 'paused':
      return 'bg-amber-400'
    case 'archived':
      return 'bg-white/30'
    default:
      return 'bg-white/30'
  }
}

interface StatusDropdownProps {
  value: ProjectStatus
  onChange: (value: ProjectStatus) => void
  label: (status: ProjectStatus) => string
}

interface WorkspaceFormState {
  name: string
  status: ProjectStatus
}

function StatusDropdown({ value, onChange, label }: StatusDropdownProps) {
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement | null>(null)

  useEffect(() => {
    if (!open) return
    const onClickOutside = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', onClickOutside)
    return () => document.removeEventListener('mousedown', onClickOutside)
  }, [open])

  return (
    <div className="relative" ref={ref}>
      <button
        type="button"
        onClick={() => setOpen((prev) => !prev)}
        aria-haspopup="listbox"
        aria-expanded={open}
        className="flex w-full items-center justify-between gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white transition hover:border-white/20"
      >
        <span className="flex items-center gap-2">
          <span className={`h-2 w-2 rounded-full ${statusDotClass(value)}`} />
          <span className="capitalize">{label(value)}</span>
        </span>
        <svg
          width="16"
          height="16"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
          className={`text-white/40 transition-transform ${open ? 'rotate-180' : ''}`}
        >
          <polyline points="6 9 12 15 18 9" />
        </svg>
      </button>
      {open ? (
        <ul
          role="listbox"
          className="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border border-white/10 bg-[#161c24] py-1 shadow-2xl shadow-black/50"
        >
          {statusOptions.map((option) => (
            <li key={option} role="option" aria-selected={option === value}>
              <button
                type="button"
                onClick={() => {
                  onChange(option)
                  setOpen(false)
                }}
                className={`flex w-full items-center gap-2 px-3 py-2 text-left text-sm transition hover:bg-white/10 ${
                  option === value ? 'bg-white/5 text-white' : 'text-white/80'
                }`}
              >
                <span className={`h-2 w-2 rounded-full ${statusDotClass(option)}`} />
                <span className="capitalize">{label(option)}</span>
                {option === value ? (
                  <svg
                    width="15"
                    height="15"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="2"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    className="ml-auto text-orange-300"
                  >
                    <polyline points="20 6 9 17 4 12" />
                  </svg>
                ) : null}
              </button>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  )
}

interface WorkspaceMetrics {
  hosts: number
  alerts: number
}

interface WorkspaceCardProps {
  item: WorkspaceListItem
  isActive: boolean
  roleBadgeClass: string
  onOpen: () => void
  onSwitch: () => void
}

function WorkspaceCard({ item, isActive, roleBadgeClass, onOpen, onSwitch }: WorkspaceCardProps) {
  const { t } = useLanguage()
  const [metrics, setMetrics] = useState<WorkspaceMetrics | null>(null)
  const [loadingMetrics, setLoadingMetrics] = useState(true)

  useEffect(() => {
    let cancelled = false
    setLoadingMetrics(true)
    observeService
      .getServices(item.project.id, { limit: 1 })
      .then((res) => {
        const payload =
          res && typeof res === 'object' && 'data' in res
            ? (res as { data: typeof res }).data
            : res
        const ht = payload?.hostTotals
        const st = payload?.serviceTotals
        const hosts = ht ? ht.up + ht.down + ht.unreachable + ht.pending : 0
        const alerts = st ? (st.warning ?? 0) + (st.critical ?? 0) : 0
        if (!cancelled) setMetrics({ hosts, alerts })
      })
      .catch(() => {
        if (!cancelled) setMetrics(null)
      })
      .finally(() => {
        if (!cancelled) setLoadingMetrics(false)
      })
    return () => {
      cancelled = true
    }
  }, [item.project.id])

  const metricValue = (value: number | undefined) => {
    if (loadingMetrics) return '—'
    if (metrics == null) return '—'
    return String(value ?? 0)
  }

  return (
    <div
      className={`rounded-2xl border p-5 text-white transition ${
        isActive ? 'border-orange-500/60 bg-orange-500/[0.06]' : 'border-white/10 bg-[#0f151d] hover:border-white/25'
      }`}
    >
      <div className="flex items-start justify-between gap-3">
        <div className="flex min-w-0 items-center gap-2">
          <span className={`h-2.5 w-2.5 shrink-0 rounded-full ${statusDotClass(item.project.status)}`} />
          <h3 className="truncate text-base font-semibold">{item.project.name}</h3>
        </div>
        {isActive ? (
          <span className="inline-flex shrink-0 items-center gap-1 rounded-full border border-orange-500/40 bg-orange-500/15 px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-orange-200">
            <span className="h-1.5 w-1.5 rounded-full bg-orange-400" />
            {t('projects.active')}
          </span>
        ) : null}
      </div>

      <div className="mt-1.5 flex items-center gap-2 text-xs text-white/50">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
          <circle cx="12" cy="10" r="3" />
        </svg>
        <span className="capitalize">{item.project.status}</span>
        <span className="text-white/20">·</span>
        <span className={`rounded-full border px-2 py-0.5 text-[10px] font-medium ${roleBadgeClass}`}>
          {item.my_role.charAt(0).toUpperCase() + item.my_role.slice(1)}
        </span>
      </div>

      <div className="mt-4 grid grid-cols-2 gap-3">
        <div className="rounded-xl border border-white/10 bg-white/5 p-3">
          <p className="text-[11px] uppercase tracking-wider text-white/50">{t('projects.hosts')}</p>
          <p className="mt-1 text-lg font-semibold text-white">{metricValue(metrics?.hosts)}</p>
        </div>
        <div className="rounded-xl border border-white/10 bg-white/5 p-3">
          <p className="text-[11px] uppercase tracking-wider text-white/50">{t('projects.activeAlerts')}</p>
          <p
            className={`mt-1 text-lg font-semibold ${
              loadingMetrics || metrics == null
                ? 'text-white'
                : (metrics?.alerts ?? 0) > 0
                ? 'text-rose-300'
                : 'text-emerald-300'
            }`}
          >
            {metricValue(metrics?.alerts)}
          </p>
        </div>
      </div>

      <div className="mt-4 flex items-center gap-2">
        {isActive ? (
          <button
            type="button"
            onClick={onOpen}
            className="inline-flex flex-1 items-center justify-center gap-2 rounded-lg bg-orange-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-orange-400"
          >
            {t('projects.openWorkspace')}
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <line x1="5" y1="12" x2="19" y2="12" />
              <polyline points="12 5 19 12 12 19" />
            </svg>
          </button>
        ) : (
          <>
            <button
              type="button"
              onClick={onSwitch}
              className="inline-flex flex-1 items-center justify-center gap-2 rounded-lg border border-orange-500/40 bg-orange-500/10 px-4 py-2 text-sm font-semibold text-orange-200 transition hover:bg-orange-500/20 hover:text-orange-100"
            >
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <polyline points="17 1 21 5 17 9" />
                <path d="M3 11V9a4 4 0 0 1 4-4h14" />
                <polyline points="7 23 3 19 7 15" />
                <path d="M21 13v2a4 4 0 0 1-4 4H3" />
              </svg>
              {t('projects.setActive')}
            </button>
            <button
              type="button"
              onClick={onOpen}
              className="inline-flex items-center justify-center rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-sm font-medium text-white/80 transition hover:bg-white/10 hover:text-white"
            >
              {t('projects.open')}
            </button>
          </>
        )}
      </div>
    </div>
  )
}

function WorkspacesPage() {
  const { t } = useLanguage()
  const navigate = useNavigate()
  const location = useLocation()
  const { 
    selectedWorkspaceId, 
    setSelectedWorkspaceId, 
    refreshWorkspaces,
    isLoadingWorkspaces,
    workspacesError
  } = useWorkspaceContext()
  const [form, setForm] = useState<WorkspaceFormState>({ name: '', status: 'active' })
  const [creating, setCreating] = useState(false)
  const [success, setSuccess] = useState<string | null>(null)
  const [hasAutoSelected, setHasAutoSelected] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // Fetch workspaces list for display (includes my_role which context doesn't have)
  const [workspacesList, setWorkspacesList] = useState<WorkspaceListItem[]>([])
  const [loading, setLoading] = useState(true)

  const loadWorkspaces = async () => {
    setLoading(true)
    setError(null)
    try {
      const workspaces = await workspaceService.getMyWorkspaces()
      setWorkspacesList(workspaces)
    } catch (err) {
      // Improved error handling - use backend error message directly
      let errorMessage = 'Failed to load workspaces'
      if (err instanceof Error) {
        // Always use the error message from the backend if available
        if (err.message && 
            err.message !== 'An error occurred' && 
            err.message !== 'An unexpected error occurred' &&
            !err.message.startsWith('HTTP ') &&
            !err.message.includes('Failed to parse') &&
            !err.message.includes('Invalid API response')) {
          // Use the actual error message from backend
          errorMessage = err.message
        } else {
          // Fallback to specific error types
          if (err.message.includes('401') || err.message.includes('Unauthorized')) {
            errorMessage = 'Authentication required'
          } else if (err.message.includes('403') || err.message.includes('Forbidden')) {
            errorMessage = 'Access denied'
          } else if (err.message.includes('404') || err.message.includes('Not Found')) {
            errorMessage = 'Workspaces not found'
          } else if (err.message.includes('500') || err.message.includes('Internal Server Error')) {
            errorMessage = 'Server error - please contact support if this persists'
          } else if (err.message.includes('Network') || err.message.includes('fetch')) {
            errorMessage = 'Network error - please check your connection'
          }
        }
      }
      setError(errorMessage)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    // If context is loading, wait for it
    if (isLoadingWorkspaces) {
      setLoading(true)
      return
    }
    
    // Use context error if available
    if (workspacesError) {
      setError(workspacesError)
      setLoading(false)
      // Still try to load workspaces list for display (might succeed even if context failed)
      loadWorkspaces()
      return
    }
    
    // Load workspaces list (includes my_role which context doesn't have)
    loadWorkspaces()
    
    // Note: Don't call refreshWorkspaces here to avoid infinite loop
    // The context will refresh workspaces on mount, and we'll sync via loadWorkspaces
  }, [workspacesError, isLoadingWorkspaces]) // Removed refreshWorkspaces from dependencies

  const handleOpenWorkspace = (workspace: WorkspaceListItem) => {
    setSelectedWorkspaceId(String(workspace.project.id))
    navigate(`/app/workspaces/${workspace.project.id}`)
  }

  const getRoleBadgeColor = (role: Role) => {
    switch (role) {
      case 'owner':
        return 'bg-purple-500/20 text-purple-200 border-purple-500/30'
      case 'admin':
        return 'bg-blue-500/20 text-blue-200 border-blue-500/30'
      case 'member':
        return 'bg-green-500/20 text-green-200 border-green-500/30'
      case 'viewer':
        return 'bg-gray-500/20 text-gray-200 border-gray-500/30'
      default:
        return 'bg-white/10 text-white/70 border-white/10'
    }
  }

  // Auto-select and redirect if exactly one workspace and none selected
  useEffect(() => {
    if (!loading && workspacesList.length === 1 && !selectedWorkspaceId && !hasAutoSelected) {
      const singleWorkspace = workspacesList[0]
      setSelectedWorkspaceId(String(singleWorkspace.project.id))
      setHasAutoSelected(true)
      // Navigate to dashboard after a brief moment to allow state to update
      setTimeout(() => {
        navigate('/dashboard', { replace: true })
      }, 100)
    }
  }, [loading, workspacesList, selectedWorkspaceId, hasAutoSelected, setSelectedWorkspaceId, navigate])

  const handleCreate = async (event?: React.FormEvent<HTMLFormElement>) => {
    if (event) {
      event.preventDefault()
    }
    if (!form.name.trim()) {
      return
    }
    setCreating(true)
    setSuccess(null)
    setError(null)
    try {
      await workspaceService.createWorkspace({
        name: form.name.trim(),
        status: form.status,
      })
      setForm({ name: '', status: 'active' })
      setSuccess(t('projects.createTitle'))
      // Refresh local list for display first
      await loadWorkspaces()
      // Then refresh context workspaces so dropdown updates (but only if not already loading)
      if (!isLoadingWorkspaces) {
        await refreshWorkspaces()
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'An unexpected error occurred')
    } finally {
      setCreating(false)
    }
  }

  const orderedWorkspaces = useMemo(() => {
    return [...workspacesList].sort((a, b) => 
      b.project.updated_at.localeCompare(a.project.updated_at)
    )
  }, [workspacesList])

  // Show loading state from context or local state
  if (loading || isLoadingWorkspaces) {
    return <div className="text-sm text-white/60">{t('common.loadingDashboard')}</div>
  }

  // Show error from context or local state
  const displayError = workspacesError || error
  
  // Check if this is an authentication error (401)
  const isAuthError = displayError && (
    displayError.includes('Unauthenticated') || 
    displayError.includes('Authentication required') ||
    displayError.includes('401')
  )
  
  if (isAuthError) {
    // For 401 errors, redirect to login with return path
    const returnPath = location.pathname + location.search
    navigate(`/login?next=${encodeURIComponent(returnPath)}`, { replace: true })
    return (
      <div className="rounded-lg border border-sky-500/30 bg-sky-500/10 px-4 py-3 text-sm text-sky-100">
        <p>Please log in to continue. Redirecting to login...</p>
      </div>
    )
  }
  
  if (displayError) {
    return (
      <div className="space-y-4">
        <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
          {displayError}
        </div>
        {displayError.includes('Network') && (
          <div className="rounded-lg border border-sky-500/30 bg-sky-500/10 px-4 py-3 text-sm text-sky-100">
            <p className="font-semibold">Connection Issue</p>
            <p className="mt-1 text-xs text-sky-200/80">
              Please check your internet connection and try again. If the problem persists, contact support.
            </p>
          </div>
        )}
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="space-y-1">
          <h1 className="text-2xl font-semibold text-white">{t('projects.title')}</h1>
          <p className="text-sm text-white/60">{t('projects.subtitle')}</p>
        </div>
        <button
          type="button"
          onClick={() => navigate('/settings/access')}
          className="inline-flex shrink-0 items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-sm font-medium text-white/80 transition hover:bg-white/10 hover:text-white"
        >
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <circle cx="12" cy="12" r="3" />
            <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 11-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 110-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 114 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 110 4h-.09a1.65 1.65 0 00-1.51 1z" />
          </svg>
          {t('projects.settings')}
        </button>
      </div>

      {orderedWorkspaces.length === 0 ? (
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-6 text-white">
          <div className="text-center mb-6">
            <h3 className="text-sm font-semibold">{t('projects.emptyTitle')}</h3>
            <p className="mt-2 text-xs text-white/60">{t('projects.emptySubtitle')}</p>
          </div>
          
          <div className="space-y-4 max-w-md mx-auto">
            <div className="space-y-2">
              <label className="text-xs text-white/60 block">Workspace Name</label>
              <input
                value={form.name}
                required
                minLength={2}
                onChange={(event) => setForm((prev) => ({ ...prev, name: event.target.value }))}
                className="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white"
                placeholder="Enter workspace name"
              />
            </div>
            
            <div className="flex flex-wrap gap-2">
              <span className="text-xs text-white/60 w-full">Suggested names:</span>
              {['Production Env', 'Staging Env', 'Product X', 'Product Y'].map((suggestion) => (
                <button
                  key={suggestion}
                  type="button"
                  onClick={() => setForm((prev) => ({ ...prev, name: suggestion }))}
                  className="rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white/70 transition hover:bg-white/10 hover:text-white"
                >
                  {suggestion}
                </button>
              ))}
            </div>
            
            <button
              type="button"
              onClick={() => handleCreate()}
              disabled={creating || !form.name.trim()}
              className="w-full rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-400 disabled:cursor-not-allowed disabled:opacity-70"
            >
              {creating ? t('projects.creating') : t('projects.createButton')}
            </button>
          </div>
        </div>
      ) : (
        <>
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {orderedWorkspaces.map((workspace) => (
              <WorkspaceCard
                key={workspace.project.id}
                item={workspace}
                isActive={selectedWorkspaceId === String(workspace.project.id)}
                roleBadgeClass={getRoleBadgeColor(workspace.my_role)}
                onOpen={() => handleOpenWorkspace(workspace)}
                onSwitch={() => setSelectedWorkspaceId(String(workspace.project.id))}
              />
            ))}
          </div>
          <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
            <h2 className="text-sm font-semibold">{t('projects.createTitle')}</h2>
            <form onSubmit={handleCreate} className="mt-4 grid gap-4 md:grid-cols-[2fr,1fr,auto]">
              <div className="space-y-1">
                <label className="text-xs text-white/60">{t('projects.nameLabel')}</label>
                <input
                  value={form.name}
                  required
                  minLength={2}
                  onChange={(event) => setForm((prev) => ({ ...prev, name: event.target.value }))}
                  className="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white"
                  placeholder="New workspace"
                />
              </div>
              <div className="space-y-1">
                <label className="text-xs text-white/60">{t('projects.statusLabel')}</label>
                <StatusDropdown
                  value={form.status}
                  onChange={(status) => setForm((prev) => ({ ...prev, status }))}
                  label={(status) => t(`projects.status.${status}`)}
                />
              </div>
              <div className="flex items-end">
                <button
                  type="submit"
                  disabled={creating}
                  className="rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-400 disabled:cursor-not-allowed disabled:opacity-70"
                >
                  {creating ? t('projects.creating') : t('projects.createButton')}
                </button>
              </div>
            </form>
            {success ? <p className="mt-3 text-xs text-emerald-300">{success}</p> : null}
          </section>
        </>
      )}
    </div>
  )
}

export default WorkspacesPage
