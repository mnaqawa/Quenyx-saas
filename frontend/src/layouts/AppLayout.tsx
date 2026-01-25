import { useState, useMemo, useEffect } from 'react'
import { Outlet, Link, useLocation, useNavigate } from 'react-router-dom'
import { useLanguage } from '../i18n/LanguageContext'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'
import { authService } from '../services/authService'
import { observeRoutes } from '../constants/observeRoutes'
import { getModuleByKey, isModuleReady } from '../constants/modules'

function AppLayout() {
  const location = useLocation()
  const navigate = useNavigate()
  const [isSidebarOpen, setIsSidebarOpen] = useState(true)
  const { language, setLanguage, t } = useLanguage()
  const { workspaces, selectedWorkspaceId, setSelectedWorkspaceId, modulesWithAccess, isLoadingModules, modulesError, allowedByKey } = useWorkspaceContext()
  
  // Find selected workspace using string comparison
  const selectedWorkspace = workspaces.find((w) => String(w.id) === selectedWorkspaceId) ?? null

  // ShieldObserve subpages configuration (from shared constants)

  // Check if ShieldObserve is locked
  const isObserveLocked = useMemo(() => {
    const observeModule = modulesWithAccess?.find((m) => m.key === 'shieldobserve')
    return observeModule ? !allowedByKey['shieldobserve'] : false
  }, [modulesWithAccess, allowedByKey])

  // Check if we're on any observe route
  const isObserveRoute = location.pathname.includes('/observe/')
  const [isObserveExpanded, setIsObserveExpanded] = useState(isObserveRoute)

  // Update expanded state when route changes to observe
  useEffect(() => {
    if (isObserveRoute) {
      setIsObserveExpanded(true)
    }
  }, [isObserveRoute])

  const isActive = (path: string): boolean => {
    if (path === '/dashboard') {
      return location.pathname === '/' || location.pathname.startsWith('/dashboard')
    }
    if (path === '/app/workspaces' || path === '/app/projects') {
      return location.pathname.startsWith('/app/workspaces') || location.pathname.startsWith('/app/projects')
    }
    return location.pathname === path
  }

  const isObserveSubpageActive = (route: string): boolean => {
    if (!selectedWorkspaceId) return false
    return location.pathname === `/app/workspaces/${selectedWorkspaceId}/observe/${route}`
  }

  const handleLogout = async () => {
    try {
      await authService.logout()
      navigate('/login')
    } catch (error) {
      // Even if logout API call fails, clear token and redirect
      navigate('/login')
    }
  }

  return (
    <div className="relative flex min-h-screen bg-[#0b0f14] text-slate-100">
      {isSidebarOpen ? (
        <button
          type="button"
          onClick={() => setIsSidebarOpen(false)}
          className="fixed inset-0 z-30 bg-black/40 md:hidden"
          aria-label="Close sidebar"
        />
      ) : null}
      <aside
        className={[
          'fixed left-0 top-0 z-40 flex h-full w-64 shrink-0 flex-col border-r border-white/5 bg-[#0f141b] text-white transition-transform md:static md:translate-x-0',
          isSidebarOpen ? 'translate-x-0' : '-translate-x-full',
        ].join(' ')}
      >
        <div className="border-b border-white/10 px-6 py-6">
          <h1 className="text-lg font-semibold leading-6">{t('app.name')}</h1>
          <p className="mt-1 text-xs text-white/50">{t('app.controlCenter')}</p>
        </div>
        <nav className="flex flex-col gap-1 px-4 py-4">
          <span className="px-3 pb-1 text-[11px] uppercase tracking-[0.2em] text-white/40">
            {t('nav.dashboard')}
          </span>
          <Link
            to="/dashboard"
            className={[
              'rounded-md px-3 py-2 text-sm font-medium transition',
              isActive('/dashboard')
                ? 'bg-white/10 text-white'
                : 'text-white/70 hover:bg-white/10 hover:text-white',
            ].join(' ')}
          >
            {t('nav.dashboard')}
          </Link>
          <Link
            to="/app/workspaces"
            className={[
              'rounded-md px-3 py-2 text-sm font-medium transition',
              isActive('/app/workspaces')
                ? 'bg-white/10 text-white'
                : 'text-white/70 hover:bg-white/10 hover:text-white',
            ].join(' ')}
          >
            {t('nav.projects')}
          </Link>
          <Link
            to="/subscriptions"
            className={[
              'rounded-md px-3 py-2 text-sm font-medium transition',
              isActive('/subscriptions')
                ? 'bg-white/10 text-white'
                : 'text-white/70 hover:bg-white/10 hover:text-white',
            ].join(' ')}
          >
            {t('nav.subscriptions')}
          </Link>
          <Link
            to="/settings/access"
            className={[
              'rounded-md px-3 py-2 text-sm font-medium transition',
              isActive('/settings/access') || isActive('/settings/members')
                ? 'bg-white/10 text-white'
                : 'text-white/70 hover:bg-white/10 hover:text-white',
            ].join(' ')}
          >
            Workspace Settings
          </Link>
          <Link
            to="/integrations"
            className={[
              'rounded-md px-3 py-2 text-sm font-medium transition',
              isActive('/integrations')
                ? 'bg-white/10 text-white'
                : 'text-white/70 hover:bg-white/10 hover:text-white',
            ].join(' ')}
          >
            {t('nav.integrations')}
          </Link>
          <Link
            to="/profile"
            className={[
              'rounded-md px-3 py-2 text-sm font-medium transition',
              isActive('/profile') ? 'bg-white/10 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white',
            ].join(' ')}
          >
            {t('nav.profile')}
          </Link>
        </nav>
        <nav className="flex flex-col gap-1 px-4 pb-6">
          <span className="px-3 pb-1 text-[11px] uppercase tracking-[0.2em] text-white/40">
            {t('nav.modules')}
          </span>
          {isLoadingModules ? (
            <div className="px-3 py-2 text-xs text-white/40">Loading modules...</div>
          ) : modulesError ? (
            <div className="px-3 py-2 text-xs text-rose-300">
              Error: {modulesError}
            </div>
          ) : !modulesWithAccess || modulesWithAccess.length === 0 ? (
            <div className="px-3 py-2 text-xs text-white/40">
              {selectedWorkspaceId ? 'No modules available' : 'Select a workspace'}
            </div>
          ) : (
            <>
              {/* ShieldObserve - always visible, even when locked, with expandable subpages */}
              <div className="relative">
                <button
                  type="button"
                  onClick={() => setIsObserveExpanded(!isObserveExpanded)}
                  className={`rounded-md px-3 py-2 text-left text-sm transition w-full flex items-center justify-between ${
                    isObserveRoute
                      ? 'bg-white/10 text-white'
                      : 'text-white/60 hover:bg-white/5 hover:text-white'
                  }`}
                >
                  <span>ShieldObserve</span>
                  <svg
                    width="12"
                    height="12"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="2"
                    className={`transition-transform ${isObserveExpanded ? 'rotate-90' : ''}`}
                  >
                    <polyline points="9 18 15 12 9 6" />
                  </svg>
                </button>
                {isObserveExpanded && (
                  <div className="ml-4 mt-1 space-y-0.5 border-l border-white/5 pl-10">
                    {observeRoutes.map((subpage) => {
                      const isActive = isObserveSubpageActive(subpage.route)
                      const isDisabled = !selectedWorkspaceId || isObserveLocked
                      const href = selectedWorkspaceId
                        ? `/app/workspaces/${selectedWorkspaceId}/observe/${subpage.route}`
                        : '#'

                      return (
                        <Link
                          key={subpage.route}
                          to={href}
                          onClick={(e) => {
                            if (isDisabled) {
                              e.preventDefault()
                            }
                          }}
                          className={`relative block rounded-md px-3 py-1.5 text-xs transition ${
                            isDisabled
                              ? 'text-white/30 cursor-not-allowed opacity-50'
                              : isActive
                              ? 'bg-white/15 text-white border-l-2 border-sky-500 pl-2.5'
                              : 'text-white/60 hover:bg-white/5 hover:text-white'
                          }`}
                        >
                          {subpage.label}
                        </Link>
                      )
                    })}
                  </div>
                )}
              </div>
              {/* Other modules */}
              {modulesWithAccess
                .filter((module) => module.key?.toLowerCase().startsWith('shield') && module.key !== 'shieldobserve')
                .filter((module, index, self) => 
                  // Deduplicate by key (defensive filter)
                  module.key && index === self.findIndex((m) => m.key === module.key)
                )
                .map((module) => {
                  const isAllowed = allowedByKey[module.key] ?? false
                  const moduleConfig = getModuleByKey(module.key)
                  const isModuleReadyStatus = isModuleReady(module.key)
                  
                  // Build navigation path
                  let navPath = '#'
                  if (!selectedWorkspaceId) {
                    // No workspace selected - keep disabled
                    navPath = '#'
                  } else if (!isModuleReadyStatus) {
                    // Module not ready - route to placeholder
                    navPath = `/app/workspaces/${selectedWorkspaceId}/modules/${module.key}`
                  } else if (isModuleReadyStatus && isAllowed && moduleConfig) {
                    // Ready and allowed - use base path
                    navPath = moduleConfig.basePath(selectedWorkspaceId)
                  } else {
                    // Ready but locked - route to placeholder (locked state handled in ComingSoon)
                    navPath = `/app/workspaces/${selectedWorkspaceId}/modules/${module.key}`
                  }

                  const isActive = location.pathname === navPath || location.pathname.startsWith(navPath + '/')

                  return (
                    <Link
                      key={module.key}
                      to={navPath}
                      onClick={(e) => {
                        if (!selectedWorkspaceId && moduleConfig?.requiresWorkspace) {
                          e.preventDefault()
                        }
                      }}
                      className={`
                        rounded-md px-3 py-2 text-left text-sm transition w-full flex items-center justify-between
                        ${
                          isActive
                            ? 'bg-white/10 text-white'
                            : !isAllowed
                            ? 'text-white/30 opacity-50'
                            : 'text-white/60 hover:bg-white/5 hover:text-white'
                        }
                      `}
                    >
                      <span>{module.name}</span>
                      {!isAllowed && (
                        <svg
                          width="14"
                          height="14"
                          viewBox="0 0 24 24"
                          fill="none"
                          stroke="currentColor"
                          strokeWidth="2"
                          className="text-white/30"
                        >
                          <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
                          <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                        </svg>
                      )}
                    </Link>
                  )
                })}
            </>
          )}
        </nav>
        <div className="mt-auto border-t border-white/10 px-4 py-4">
          <button
            type="button"
            onClick={handleLogout}
            className="flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-white/70 transition hover:bg-white/10 hover:text-white"
          >
            <svg
              width="16"
              height="16"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
              <polyline points="16 17 21 12 16 7" />
              <line x1="21" y1="12" x2="9" y2="12" />
            </svg>
            Logout
          </button>
        </div>
      </aside>

      <main className="min-h-screen min-w-0 flex-1 bg-[#0b0f14] text-slate-100 md:ml-64">
        <div className="border-b border-white/5 px-4 py-4 md:px-6">
          <div className="mx-auto flex max-w-6xl flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <button
              type="button"
              onClick={() => setIsSidebarOpen((prev) => !prev)}
              className="inline-flex items-center justify-center rounded-md border border-white/10 p-2 text-white/70 transition hover:bg-white/10 hover:text-white"
              aria-label={isSidebarOpen ? 'Hide sidebar' : 'Show sidebar'}
            >
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                <path
                  d="M4 6h16M4 12h16M4 18h10"
                  stroke="currentColor"
                  strokeWidth="1.6"
                  strokeLinecap="round"
                />
              </svg>
            </button>
            <div className="flex flex-wrap items-center gap-2">
              <div className="flex items-center gap-2 text-xs text-white/70">
                <span>{t('nav.projects')}</span>
                <select
                  value={selectedWorkspaceId ?? ''}
                  onChange={(event) => {
                    const workspaceId = event.target.value
                    if (workspaceId) {
                      setSelectedWorkspaceId(workspaceId)
                      // Navigate to dashboard after selection
                      navigate('/dashboard')
                    }
                  }}
                  className="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs text-white"
                >
                  {workspaces.length === 0 ? (
                    <option value="">No workspaces</option>
                  ) : !selectedWorkspaceId ? (
                    <option value="">Select a workspace</option>
                  ) : selectedWorkspace ? null : (
                    <option value={selectedWorkspaceId}>Selected workspace</option>
                  )}
                  {workspaces.map((workspace) => (
                    <option key={workspace.id} value={String(workspace.id)} className="bg-slate-900 text-white">
                      {workspace.name}
                    </option>
                  ))}
                </select>
              </div>
              <span className="inline-flex items-center gap-2 text-xs text-white/70">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                  <path
                    d="M12 2a10 10 0 100 20 10 10 0 000-20z"
                    stroke="currentColor"
                    strokeWidth="1.6"
                  />
                  <path
                    d="M2 12h20M12 2c3 3 3 15 0 20M12 2c-3 3-3 15 0 20"
                    stroke="currentColor"
                    strokeWidth="1.2"
                  />
                </svg>
                {t('language.switch')}
              </span>
              <button
                type="button"
                onClick={() => setLanguage('en')}
                className={`rounded-full px-3 py-1 text-xs ${
                  language === 'en' ? 'bg-white/15 text-white' : 'text-white/60 hover:bg-white/10'
                }`}
              >
                {t('language.english')}
              </button>
              <button
                type="button"
                onClick={() => setLanguage('ar')}
                className={`rounded-full px-3 py-1 text-xs ${
                  language === 'ar' ? 'bg-white/15 text-white' : 'text-white/60 hover:bg-white/10'
                }`}
              >
                {t('language.arabic')}
              </button>
            </div>
          </div>
        </div>
        <div className="mx-auto max-w-6xl px-4 py-6 md:px-6 md:py-8">
          <Outlet />
        </div>
      </main>
    </div>
  )
}

export default AppLayout
