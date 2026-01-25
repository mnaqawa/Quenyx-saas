import { useState, useMemo, useEffect } from 'react'
import { Outlet, Link, useLocation, useNavigate } from 'react-router-dom'
import { useLanguage } from '../i18n/LanguageContext'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'
import { authService } from '../services/authService'
import { routesByModule, getModule, isModuleReady, getModuleBasePath, isModuleLocked } from '../constants/platformRegistry'

function AppLayout() {
  const location = useLocation()
  const navigate = useNavigate()
  const [isSidebarOpen, setIsSidebarOpen] = useState(true)
  const { t } = useLanguage()
  const { selectedWorkspaceId, modulesWithAccess, isLoadingModules, modulesError, allowedByKey } = useWorkspaceContext()

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
                    {(routesByModule.shieldobserve || []).map((route) => {
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
              {/* Other modules - use platformRegistry */}
              {modulesWithAccess
                .filter((module) => {
                  // Filter out invalid modules
                  if (!module || !module.key) return false
                  return module.key.toLowerCase().startsWith('shield') && module.key !== 'shieldobserve'
                })
                .filter((module, index, self) => 
                  // Deduplicate by key (defensive filter)
                  module.key && index === self.findIndex((m) => m.key === module.key)
                )
                .map((module) => {
                  // Safely get module config with fallback
                  const moduleConfig = getModule(module.key)
                  if (!moduleConfig) {
                    // If module not in registry, skip it (defensive)
                    console.warn(`Module ${module.key} not found in platformRegistry`)
                    return null
                  }

                  try {
                    const isAllowed = allowedByKey[module.key] ?? false
                    const isModuleReadyStatus = isModuleReady(module.key)
                    const isLocked = isModuleLocked(module.key, allowedByKey)
                    
                    // Build navigation path using platformRegistry
                    let navPath = '#'
                    if (!selectedWorkspaceId) {
                      // No workspace selected - keep disabled
                      navPath = '#'
                    } else if (!isModuleReadyStatus) {
                      // Module not ready - route to placeholder
                      navPath = getModuleBasePath(module.key, selectedWorkspaceId)
                    } else if (isModuleReadyStatus && isAllowed) {
                      // Ready and allowed - use base path (first route if available)
                      const routes = routesByModule[module.key]
                      if (routes && routes.length > 0) {
                        navPath = routes[0].path.replace(':id', String(selectedWorkspaceId))
                      } else {
                        navPath = getModuleBasePath(module.key, selectedWorkspaceId)
                      }
                    } else {
                      // Ready but locked - route to placeholder (locked state handled in ComingSoon)
                      navPath = getModuleBasePath(module.key, selectedWorkspaceId)
                    }

                    const isActive = location.pathname === navPath || location.pathname.startsWith(navPath + '/')

                    return (
                      <Link
                        key={module.key}
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
                    console.error(`Error rendering module ${module.key}:`, err)
                    return null
                  }
                })
                .filter((item) => item !== null)} {/* Remove null items from map */}
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

      {/* Mobile sidebar overlay */}
      {isSidebarOpen && (
        <button
          type="button"
          onClick={() => setIsSidebarOpen(false)}
          className="fixed inset-0 z-30 bg-black/40 md:hidden"
          aria-label="Close sidebar"
        />
      )}
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
              isActive('/settings/access')
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
                    {(routesByModule.shieldobserve || []).map((route) => {
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
              {/* Other modules - use platformRegistry */}
              {modulesWithAccess
                .filter((module) => {
                  // Filter out invalid modules
                  if (!module || !module.key) return false
                  return module.key.toLowerCase().startsWith('shield') && module.key !== 'shieldobserve'
                })
                .filter((module, index, self) => 
                  // Deduplicate by key (defensive filter)
                  module.key && index === self.findIndex((m) => m.key === module.key)
                )
                .map((module) => {
                  // Safely get module config with fallback
                  const moduleConfig = getModule(module.key)
                  if (!moduleConfig) {
                    // If module not in registry, skip it (defensive)
                    console.warn(`Module ${module.key} not found in platformRegistry`)
                    return null
                  }

                  try {
                    const isAllowed = allowedByKey[module.key] ?? false
                    const isModuleReadyStatus = isModuleReady(module.key)
                    const isLocked = isModuleLocked(module.key, allowedByKey)
                    
                    // Build navigation path using platformRegistry
                    let navPath = '#'
                    if (!selectedWorkspaceId) {
                      // No workspace selected - keep disabled
                      navPath = '#'
                    } else if (!isModuleReadyStatus) {
                      // Module not ready - route to placeholder
                      navPath = getModuleBasePath(module.key, selectedWorkspaceId)
                    } else if (isModuleReadyStatus && isAllowed) {
                      // Ready and allowed - use base path (first route if available)
                      const routes = routesByModule[module.key]
                      if (routes && routes.length > 0) {
                        navPath = routes[0].path.replace(':id', String(selectedWorkspaceId))
                      } else {
                        navPath = getModuleBasePath(module.key, selectedWorkspaceId)
                      }
                    } else {
                      // Ready but locked - route to placeholder (locked state handled in ComingSoon)
                      navPath = getModuleBasePath(module.key, selectedWorkspaceId)
                    }

                    const isActive = location.pathname === navPath || location.pathname.startsWith(navPath + '/')

                    return (
                      <Link
                        key={module.key}
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
                    console.error(`Error rendering module ${module.key}:`, err)
                    return null
                  }
                })
                .filter((item) => item !== null)} {/* Remove null items from map */}
            </>
          )}
        </nav>
        <div className="mt-auto border-t border-white/10 px-4 py-4">
          <button
            type="button"
            onClick={handleLogout}
            className="flex w-full items-center gap-2 rounded-md px-3 py-2 text-sm text-white/60 transition hover:bg-white/5 hover:text-white"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
              <polyline points="16 17 21 12 16 7" />
              <line x1="21" y1="12" x2="9" y2="12" />
            </svg>
            Logout
          </button>
        </div>
      </aside>
      <main className="flex-1 overflow-y-auto bg-slate-50">
        <Outlet />
      </main>
    </div>
  )
}

export default AppLayout
