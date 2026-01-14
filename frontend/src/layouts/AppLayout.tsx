import { Outlet, Link, useLocation } from 'react-router-dom'

function AppLayout() {
  const location = useLocation()

  const isActive = (path: string): boolean => {
    return location.pathname === path
  }

  return (
    <div className="flex min-h-screen bg-slate-100">
      {/* Keep sidebar theme (dark) */}
      <aside className="w-64 shrink-0 bg-[#1a1a1a] text-white">
        <div className="border-b border-white/10 px-6 py-6">
          <h1 className="text-lg font-semibold leading-6">PortShield</h1>
        </div>
        <nav className="flex flex-col gap-1 px-4 py-4">
          <Link
            to="/"
            className={[
              'rounded-md px-3 py-2 text-sm font-medium transition',
              isActive('/') ? 'bg-indigo-500 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white',
            ].join(' ')}
          >
            Dashboard
          </Link>
          <Link
            to="/subscriptions"
            className={[
              'rounded-md px-3 py-2 text-sm font-medium transition',
              isActive('/subscriptions')
                ? 'bg-indigo-500 text-white'
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
                ? 'bg-indigo-500 text-white'
                : 'text-white/70 hover:bg-white/10 hover:text-white',
            ].join(' ')}
          >
            Integrations
          </Link>
          <Link
            to="/profile"
            className={[
              'rounded-md px-3 py-2 text-sm font-medium transition',
              isActive('/profile') ? 'bg-indigo-500 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white',
            ].join(' ')}
          >
            Profile
          </Link>
        </nav>
      </aside>

      {/* Main content: force readable typography on light background */}
      <main className="min-w-0 flex-1 bg-white text-slate-900">
        <div className="mx-auto max-w-6xl px-6 py-6">
        <Outlet />
        </div>
      </main>
    </div>
  )
}

export default AppLayout
