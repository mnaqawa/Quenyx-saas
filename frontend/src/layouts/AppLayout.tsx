import { useState } from 'react'
import { Outlet, Link, useLocation } from 'react-router-dom'
import { useLanguage } from '../i18n/LanguageContext'
import { useProjectContext } from '../projects/ProjectContext'

// Map module display names to entitlement keys
const moduleNameToKey = (name: string): string => {
  return name.toLowerCase().replace(/\s+/g, '')
}

function AppLayout() {
  const location = useLocation()
  const [isSidebarOpen, setIsSidebarOpen] = useState(true)
  const { language, setLanguage, t } = useLanguage()
  const { projects, selectedProjectId, setSelectedProjectId, entitlements, isLoadingEntitlements } = useProjectContext()
  const [tooltipModule, setTooltipModule] = useState<string | null>(null)

  const isActive = (path: string): boolean => {
    if (path === '/dashboard') {
      return location.pathname === '/' || location.pathname.startsWith('/dashboard')
    }
    if (path === '/app/projects') {
      return location.pathname.startsWith('/app/projects')
    }
    return location.pathname === path
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
          'fixed left-0 top-0 z-40 h-full w-64 shrink-0 border-r border-white/5 bg-[#0f141b] text-white transition-transform md:static md:translate-x-0',
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
            to="/app/projects"
            className={[
              'rounded-md px-3 py-2 text-sm font-medium transition',
              isActive('/app/projects')
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
          {[
            'ShieldCore',
            'ShieldObserve',
            'ShieldInventory',
            'ShieldRespond',
            'ShieldSecure',
            'ShieldNotify',
            'ShieldVoice',
            'ShieldKnowledge',
            'ShieldAutomate',
            'ShieldBalance',
            'ShieldDesk',
          ].map((label) => {
            const moduleKey = moduleNameToKey(label)
            const isAllowed = entitlements?.modules_allowed.includes(moduleKey) ?? false
            const isDisabled = !isLoadingEntitlements && !isAllowed

            return (
              <div key={label} className="relative">
                <button
                  type="button"
                  onClick={() => {
                    if (isDisabled) {
                      setTooltipModule(label)
                      setTimeout(() => setTooltipModule(null), 2000)
                    }
                  }}
                  disabled={isDisabled}
                  className={`
                    rounded-md px-3 py-2 text-left text-sm transition w-full flex items-center justify-between
                    ${
                      isDisabled
                        ? 'text-white/30 cursor-not-allowed opacity-50'
                        : 'text-white/60 hover:bg-white/5 hover:text-white'
                    }
                  `}
                >
                  <span>{label}</span>
                  {isDisabled && (
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
                </button>
                {tooltipModule === label && (
                  <div className="absolute left-full ml-2 top-0 z-50 rounded-md bg-rose-500 px-3 py-2 text-xs text-white shadow-lg">
                    Upgrade plan to access this module
                    <div className="absolute left-0 top-1/2 -ml-1 h-2 w-2 -translate-y-1/2 rotate-45 bg-rose-500"></div>
                  </div>
                )}
              </div>
            )
          })}
        </nav>
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
                  value={selectedProjectId ?? ''}
                  onChange={(event) => setSelectedProjectId(Number(event.target.value))}
                  className="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs text-white"
                >
                  {projects.length === 0 ? (
                    <option value="">No projects</option>
                  ) : (
                    projects.map((project) => (
                      <option key={project.id} value={project.id} className="text-slate-900">
                        {project.name}
                      </option>
                    ))
                  )}
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
