import { useState, useMemo, useEffect, useRef } from 'react'
import { Outlet, Link, useLocation, useNavigate } from 'react-router-dom'
import { useLanguage } from '../i18n/LanguageContext'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'
import { authService, type AuthUser } from '../services/authService'
import { routesByModule, isModuleReady, getModuleBasePath, isModuleLocked, modules as platformModules, isModuleTemporarilyVisible } from '../constants/platformRegistry'
import { AIAgentDrawer } from '../components/ai/AIAgentDrawer'
import { ProductTourProvider, useProductTour } from '../tour/ProductTour'
import { useOnboarding } from '../onboarding/OnboardingContext'

function AppLayout() {
  return (
    <ProductTourProvider>
      <AppLayoutInner />
    </ProductTourProvider>
  )
}

function AppLayoutInner() {
  const location = useLocation()
  const navigate = useNavigate()
  const [isSidebarOpen, setIsSidebarOpen] = useState(
    () => (typeof window === 'undefined' ? true : window.innerWidth >= 768),
  )
  const { language, setLanguage, t } = useLanguage()
  const { workspaces, selectedWorkspaceId, setSelectedWorkspaceId, modulesWithAccess, isLoadingModules, modulesError, allowedByKey, isLoadingWorkspaces, workspacesError } = useWorkspaceContext()
  const { startTour } = useProductTour()
  const { isOnboarded } = useOnboarding()
  const [isAiAgentOpen, setIsAiAgentOpen] = useState(false)
  const [isUserMenuOpen, setIsUserMenuOpen] = useState(false)
  const [isModulesExpanded, setIsModulesExpanded] = useState(true)
  const [currentUser, setCurrentUser] = useState<AuthUser | null>(null)
  const userMenuRef = useRef<HTMLDivElement>(null)
  const firstRunRedirectDone = useRef(false)

  // First-time login: route the user to the Getting Started page once. The flag
  // (quenyx.onboarded) is set when they finish the tour or click "Mark complete",
  // so returning users land on their normal page and can navigate freely.
  useEffect(() => {
    if (firstRunRedirectDone.current) return
    firstRunRedirectDone.current = true
    if (!isOnboarded && location.pathname !== '/getting-started') {
      navigate('/getting-started', { replace: true })
    }
    // Intentionally run once on mount; later navigation must not re-trigger.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // Collapse the sidebar overlay automatically after navigating on mobile.
  useEffect(() => {
    if (typeof window !== 'undefined' && window.innerWidth < 768) {
      setIsSidebarOpen(false)
    }
  }, [location.pathname])

  useEffect(() => {
    let cancelled = false
    authService
      .me()
      .then((user) => {
        if (!cancelled) setCurrentUser(user)
      })
      .catch(() => {
        // Ignore: header still works without user details
      })
    return () => {
      cancelled = true
    }
  }, [])

  useEffect(() => {
    if (!isUserMenuOpen) return
    const handleClickOutside = (event: MouseEvent) => {
      if (userMenuRef.current && !userMenuRef.current.contains(event.target as Node)) {
        setIsUserMenuOpen(false)
      }
    }
    const handleEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setIsUserMenuOpen(false)
    }
    document.addEventListener('mousedown', handleClickOutside)
    document.addEventListener('keydown', handleEscape)
    return () => {
      document.removeEventListener('mousedown', handleClickOutside)
      document.removeEventListener('keydown', handleEscape)
    }
  }, [isUserMenuOpen])

  const userInitials = useMemo(() => {
    const name = currentUser?.name?.trim()
    if (!name) return 'U'
    const parts = name.split(/\s+/)
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
  }, [currentUser])

  // QynSight subpages configuration (from shared constants)

  // Check if QynSight is locked
  const isObserveLocked = useMemo(() => {
    const observeModule = modulesWithAccess?.find((m) => m.key === 'qynsight')
    return observeModule ? !allowedByKey['qynsight'] : false
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

  const handleLogout = async () => {
    try {
      await authService.logout()
      navigate('/login')
    } catch {
      // Even if logout API call fails, clear token and redirect
      navigate('/login')
    }
  }

  return (
    <div id="app-layout" className="relative flex min-h-screen bg-[#0b0f14] text-slate-100">
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
          'fixed left-0 top-0 z-40 flex h-full w-64 shrink-0 flex-col border-r border-white/5 bg-[#0f141b] text-white transition-transform',
          isSidebarOpen ? 'translate-x-0' : '-translate-x-full',
        ].join(' ')}
      >
        <div className="border-b border-white/10 px-6 py-6">
          <div className="flex items-center gap-3">
            <img src="/quenyx-logo.png" alt="Quenyx" className="h-9 w-9 shrink-0 object-contain" />
            <div>
              <h1 className="text-lg font-semibold leading-6">{t('app.name')}</h1>
              <p className="mt-0.5 text-xs text-white/50">{t('app.controlCenter')}</p>
            </div>
          </div>
        </div>
        <div data-tour="tour-nav">
        <nav className="flex flex-col gap-1 px-4 py-4">
          <span className="px-3 pb-1 text-[11px] uppercase tracking-[0.2em] text-white/40">
            {t('nav.menu')}
          </span>
          {!isOnboarded && (
            <Link
              to="/getting-started"
              className={[
                'rounded-md px-3 py-2 text-sm font-medium transition',
                isActive('/getting-started')
                  ? 'bg-white/10 text-white'
                  : 'text-white/70 hover:bg-white/10 hover:text-white',
              ].join(' ')}
            >
              {t('nav.gettingStarted')}
            </Link>
          )}
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
        </nav>
        <nav className="flex flex-col gap-1 px-4 pb-6" data-tour="tour-modules">
          <button
            type="button"
            onClick={() => setIsModulesExpanded((prev) => !prev)}
            aria-expanded={isModulesExpanded}
            className="flex w-full items-center justify-between rounded-md px-3 pb-1 pt-1 text-[11px] uppercase tracking-[0.2em] text-white/40 transition hover:text-white/70"
          >
            <span>{t('nav.modules')}</span>
            <svg
              width="12"
              height="12"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              className={`transition-transform ${isModulesExpanded ? 'rotate-180' : ''}`}
            >
              <polyline points="6 9 12 15 18 9" />
            </svg>
          </button>
          {!isModulesExpanded ? null : isLoadingModules ? (
            <div className="px-3 py-2 text-xs text-white/40">Loading modules...</div>
          ) : modulesError && !modulesError.includes('Unauthenticated') ? (
            <div className="px-3 py-2 text-xs text-rose-300">
              Error: {modulesError}
            </div>
          ) : !modulesWithAccess || modulesWithAccess.length === 0 ? (
            <div className="px-3 py-2 text-xs text-white/40">
              {selectedWorkspaceId ? 'No modules available' : 'Select a workspace'}
            </div>
          ) : (
            <>
              {/* QynSight - always visible, even when locked, with expandable subpages */}
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
                  <span>QynSight</span>
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
                    {(routesByModule.qynsight || []).map((route) => {
                      const routePath = selectedWorkspaceId
                        ? route.path.replace(':id', String(selectedWorkspaceId))
                        : '#'
                      const isActive = location.pathname === routePath || location.pathname.startsWith(routePath + '/')
                      const isDisabled = !selectedWorkspaceId || isObserveLocked

                      return (
                        <Link
                          key={route.key}
                          to={routePath}
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
                          {route.label}
                        </Link>
                      )
                    })}
                  </div>
                )}
              </div>
              {/* Other modules - use platformRegistry as source of truth */}
              {platformModules
                .filter((moduleConfig) => {
                  // Show all modules except qynsight (which is shown separately above)
                  return moduleConfig.key !== 'qynsight' && isModuleTemporarilyVisible(moduleConfig.key)
                })
                .sort((a, b) => (a.sidebar.order || 999) - (b.sidebar.order || 999))
                .map((moduleConfig) => {
                  try {
                    // Check if module has access from API
                    const isAllowed = allowedByKey[moduleConfig.key] ?? false
                    const isModuleReadyStatus = isModuleReady(moduleConfig.key)
                    const isLocked = isModuleLocked(moduleConfig.key, allowedByKey)
                    
                    // Build navigation path using platformRegistry
                    let navPath = '#'
                    if (!selectedWorkspaceId) {
                      // No workspace selected - keep disabled
                      navPath = '#'
                    } else if (!isModuleReadyStatus) {
                      // Module not ready - route to placeholder
                      navPath = getModuleBasePath(moduleConfig.key, selectedWorkspaceId)
                    } else if (isModuleReadyStatus && isAllowed) {
                      // Ready and allowed - use base path (first route if available)
                      const routes = routesByModule[moduleConfig.key]
                      if (routes && routes.length > 0) {
                        navPath = routes[0].path.replace(':id', String(selectedWorkspaceId))
                      } else {
                        navPath = getModuleBasePath(moduleConfig.key, selectedWorkspaceId)
                      }
                    } else {
                      // Ready but locked - route to placeholder (locked state handled in ComingSoon)
                      navPath = getModuleBasePath(moduleConfig.key, selectedWorkspaceId)
                    }

                    const isActive = location.pathname === navPath || location.pathname.startsWith(navPath + '/')

                    return (
                      <Link
                        key={moduleConfig.key}
                        to={navPath}
                        onClick={(e) => {
                          if (!selectedWorkspaceId && moduleConfig.requiresWorkspace) {
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
                        <span>{moduleConfig.displayName}</span>
                        {isLocked && (
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
                  } catch (err) {
                    // Defensive error handling for module rendering
                    console.error(`Error rendering module ${moduleConfig.key}:`, err)
                    return null
                  }
                })
                .filter((item) => item !== null)} {/* Remove null items from map */}
            </>
          )}
        </nav>
        </div>
        <div className="relative mt-auto border-t border-white/10 px-3 py-3" ref={userMenuRef}>
          {isUserMenuOpen && (
            <div className="absolute bottom-full left-3 right-3 mb-2 overflow-hidden rounded-xl border border-white/10 bg-[#161c24] py-1 shadow-2xl shadow-black/50">
              <Link
                to="/profile"
                onClick={() => setIsUserMenuOpen(false)}
                className="flex items-center gap-3 px-4 py-2.5 text-sm text-white/80 transition hover:bg-white/10 hover:text-white"
              >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" />
                  <circle cx="12" cy="7" r="4" />
                </svg>
                {t('nav.profile')}
              </Link>
              <Link
                to="/subscriptions"
                onClick={() => setIsUserMenuOpen(false)}
                className="flex items-center gap-3 px-4 py-2.5 text-sm text-white/80 transition hover:bg-white/10 hover:text-white"
              >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <rect x="2" y="5" width="20" height="14" rx="2" />
                  <line x1="2" y1="10" x2="22" y2="10" />
                </svg>
                {t('nav.subscriptions')}
              </Link>
              {selectedWorkspaceId ? (
                <Link
                  to={`/app/workspaces/${selectedWorkspaceId}/qyncore/billing`}
                  onClick={() => setIsUserMenuOpen(false)}
                  className="flex items-center gap-3 px-4 py-2.5 text-sm text-white/80 transition hover:bg-white/10 hover:text-white"
                >
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <line x1="12" y1="1" x2="12" y2="23" />
                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                  </svg>
                  {t('nav.billing')}
                </Link>
              ) : null}
              <Link
                to="/settings/access"
                onClick={() => setIsUserMenuOpen(false)}
                className="flex items-center gap-3 px-4 py-2.5 text-sm text-white/80 transition hover:bg-white/10 hover:text-white"
              >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <circle cx="12" cy="12" r="3" />
                  <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
                </svg>
                {t('projects.settings')}
              </Link>
              <div className="my-1 border-t border-white/10" />
              <button
                type="button"
                onClick={() => {
                  setIsUserMenuOpen(false)
                  handleLogout()
                }}
                className="flex w-full items-center gap-3 px-4 py-2.5 text-sm font-medium text-rose-300 transition hover:bg-rose-500/10 hover:text-rose-200"
              >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                  <polyline points="16 17 21 12 16 7" />
                  <line x1="21" y1="12" x2="9" y2="12" />
                </svg>
                {t('nav.logout')}
              </button>
            </div>
          )}
          <button
            type="button"
            onClick={() => setIsUserMenuOpen((prev) => !prev)}
            aria-haspopup="menu"
            aria-expanded={isUserMenuOpen}
            className="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left transition hover:bg-white/10"
          >
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-orange-500/20 text-xs font-semibold text-orange-200">
              {userInitials}
            </span>
            <span className="min-w-0 flex-1">
              <span className="block truncate text-sm font-medium text-white">
                {currentUser?.name ?? 'Account'}
              </span>
              <span className="block truncate text-xs text-white/50">
                {currentUser?.email ?? ''}
              </span>
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
              className={`shrink-0 text-white/40 transition-transform ${isUserMenuOpen ? 'rotate-180' : ''}`}
            >
              <polyline points="18 15 12 9 6 15" />
            </svg>
          </button>
        </div>
      </aside>
      <main
        className={[
          'min-h-screen min-w-0 flex-1 bg-[#0b0f14] text-slate-100 transition-[margin]',
          isSidebarOpen ? 'md:ml-64' : 'md:ml-0',
        ].join(' ')}
      >
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
              <button
                type="button"
                onClick={startTour}
                className="inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-medium text-white/70 transition hover:bg-white/10 hover:text-white"
              >
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <circle cx="12" cy="12" r="10" />
                  <path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3" />
                  <line x1="12" y1="17" x2="12.01" y2="17" />
                </svg>
                {t('tour.button')}
              </button>
              <div className="flex items-center gap-2 text-xs text-white/70" data-tour="tour-workspace">
                <span className="text-white/50">Workspace:</span>
                <select
                  value={selectedWorkspaceId ?? ''}
                  onChange={(event) => {
                    const workspaceId = event.target.value
                    if (workspaceId) {
                      setSelectedWorkspaceId(workspaceId)
                      navigate('/dashboard')
                    }
                  }}
                  className="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs text-white"
                  title={workspacesError || undefined}
                >
                  {isLoadingWorkspaces ? (
                    <option value="">Loading workspaces...</option>
                  ) : workspacesError && !workspacesError.includes('Unauthenticated') ? (
                    <option value="">Error loading workspaces</option>
                  ) : workspaces.length === 0 ? (
                    <option value="">No workspaces</option>
                  ) : !selectedWorkspaceId ? (
                    <option value="">Select a workspace</option>
                  ) : null}
                  {selectedWorkspaceId && !workspaces.some((w) => String(w.id) === String(selectedWorkspaceId)) ? (
                    <option value={selectedWorkspaceId}>Workspace {selectedWorkspaceId}</option>
                  ) : null}
                  {workspaces.map((workspace) => (
                    <option key={workspace.id} value={String(workspace.id)} className="bg-slate-900 text-white">
                      {workspace.name}
                    </option>
                  ))}
                </select>
              </div>
              <div className="flex items-center gap-2" data-tour="tour-language">
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
              <button
                type="button"
                data-tour="tour-ai-agent"
                onClick={() => setIsAiAgentOpen(true)}
                className="inline-flex items-center gap-1.5 rounded-lg border border-orange-500/40 bg-orange-500/15 px-3 py-1.5 text-xs font-semibold text-orange-100 transition hover:bg-orange-500/25"
              >
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <path d="M12 3l1.5 4.5L18 9l-4.5 1.5L12 15l-1.5-4.5L6 9l4.5-1.5L12 3z" />
                </svg>
                AI Agent
                <span className="rounded-full bg-orange-500/30 px-1.5 py-0.5 text-[9px] font-bold text-orange-100">
                  new
                </span>
              </button>
            </div>
          </div>
        </div>
        <div className="mx-auto max-w-6xl px-4 py-6 md:px-6 md:py-8">
          <Outlet />
        </div>
      </main>
      <AIAgentDrawer
        open={isAiAgentOpen}
        onClose={() => setIsAiAgentOpen(false)}
        workspaceId={selectedWorkspaceId ? Number(selectedWorkspaceId) : null}
      />
    </div>
  )
}

export default AppLayout
