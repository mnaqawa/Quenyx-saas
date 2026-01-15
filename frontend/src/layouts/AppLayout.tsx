import { Outlet, Link, useLocation } from 'react-router-dom'

function AppLayout() {
  const location = useLocation()

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
          <h1 className="text-lg font-semibold leading-6">PortShield SaaS</h1>
          <p className="mt-1 text-xs text-white/50">Control Center</p>
        </div>
        <nav className="flex flex-col gap-1 px-4 py-4">
          <span className="px-3 pb-1 text-[11px] uppercase tracking-[0.2em] text-white/40">
            Dashboard
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
            Dashboard
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
            Subscriptions
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
            Integrations
          </Link>
          <Link
            to="/profile"
            className={[
              'rounded-md px-3 py-2 text-sm font-medium transition',
              isActive('/profile') ? 'bg-white/10 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white',
            ].join(' ')}
          >
            Profile
          </Link>
        </nav>
        <nav className="flex flex-col gap-1 px-4 pb-6">
          <span className="px-3 pb-1 text-[11px] uppercase tracking-[0.2em] text-white/40">
            Modules
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
