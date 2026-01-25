import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useLocation } from 'react-router-dom'
import { workspaceService } from '../services/workspaceService'
import { CreateProjectInput, ProjectStatus } from '../types/project'
import { WorkspaceListItem, Role } from '../types/workspace'
import { useLanguage } from '../i18n/LanguageContext'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'

const statusOptions: ProjectStatus[] = ['active', 'paused', 'archived']

const formatDate = (value: string) => {
  return new Date(value).toLocaleDateString()
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
  const [form, setForm] = useState<CreateProjectInput>({ name: '', status: 'active' })
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
    
    // Refresh context workspaces to keep dropdown in sync (if not already loading)
    if (!isLoadingWorkspaces) {
      refreshWorkspaces()
    }
  }, [refreshWorkspaces, workspacesError, isLoadingWorkspaces])

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
      // Refresh context workspaces so dropdown updates immediately
      await refreshWorkspaces()
      // Also refresh local list for display
      await loadWorkspaces()
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
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold text-white">{t('projects.title')}</h1>
        <p className="text-sm text-white/60">{t('projects.subtitle')}</p>
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
                <select
                  value={form.status}
                  onChange={(event) =>
                    setForm((prev) => ({ ...prev, status: event.target.value as ProjectStatus }))
                  }
                  className="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white"
                >
                  {statusOptions.map((option) => (
                    <option key={option} value={option} className="text-slate-900">
                      {option}
                    </option>
                  ))}
                </select>
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
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {orderedWorkspaces.map((workspace) => (
            <div
              key={workspace.project.id}
              className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white transition hover:border-white/20 cursor-pointer"
              onClick={() => handleOpenWorkspace(workspace)}
            >
              <div className="flex items-start justify-between gap-3">
                <div className="flex-1">
                  <h3 className="text-sm font-semibold">{workspace.project.name}</h3>
                  <p className="text-xs text-white/50">{workspace.project.status}</p>
                </div>
                <span className={`rounded-full border px-3 py-1 text-[10px] font-medium ${getRoleBadgeColor(workspace.my_role)}`}>
                  {workspace.my_role.charAt(0).toUpperCase() + workspace.my_role.slice(1)}
                </span>
              </div>
              <div className="mt-4 flex items-center justify-between">
                <span className="text-xs text-white/60">
                  {t('projects.updatedAt')} {formatDate(workspace.project.updated_at)}
                </span>
                <span className="text-xs text-sky-200">Open →</span>
              </div>
            </div>
          ))}
        </div>
        </>
      )}
    </div>
  )
}

export default WorkspacesPage
