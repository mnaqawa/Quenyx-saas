import { Outlet, Link, useLocation } from 'react-router-dom'
import { useLanguage } from '../i18n/LanguageContext'

function AppLayout() {
  const location = useLocation()
  const { language, setLanguage, t } = useLanguage()

  const isActive = (path: string): boolean => {
    if (path === '/dashboard') {
      return location.pathname === '/' || location.pathname.startsWith('/dashboard')
    }
    return location.pathname === path
  }

  return (
    <div className="flex min-h-screen bg-[#0b0f14] text-slate-100">
      <aside className="w-64 shrink-0 border-r border-white/5 bg-[#0f141b] text-white">
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
          ].map((label) => (
            <button
              key={label}
              type="button"
              className="rounded-md px-3 py-2 text-left text-sm text-white/60 transition hover:bg-white/5 hover:text-white"
            >
              {label}
            </button>
          ))}
        </nav>
        <div className="border-t border-white/10 px-4 py-4">
          <p className="text-[10px] uppercase tracking-[0.2em] text-white/40">{t('language.switch')}</p>
          <div className="mt-2 flex items-center gap-2">
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
      </aside>

      <main className="min-w-0 flex-1 bg-[#0b0f14] text-slate-100">
        <div className="mx-auto max-w-6xl px-6 py-8">
          <Outlet />
        </div>
      </main>
    </div>
  )
}

export default AppLayout
