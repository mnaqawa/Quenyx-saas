import { useEffect, useMemo, useState } from 'react'
import { authService, AuthUser } from '../services/authService'
import { moduleService } from '../services/moduleService'
import { integrationService } from '../services/integrationService'
import { profileService, ProfileStats } from '../services/profileService'
import type { Module } from '../services/dashboardService'
import type { Integration } from '../services/integrationService'

function Profile() {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [modules, setModules] = useState<Module[]>([])
  const [integrations, setIntegrations] = useState<Integration[]>([])
  const [stats, setStats] = useState<ProfileStats | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const fetchProfile = async () => {
      try {
        const [userData, moduleData, integrationData, statsData] = await Promise.all([
          authService.me(),
          moduleService.getModules(),
          integrationService.getIntegrations(),
          profileService.getStats(),
        ])
        setUser(userData)
        setModules(moduleData)
        setIntegrations(integrationData)
        setStats(statsData)
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load profile')
      } finally {
        setLoading(false)
      }
    }

    fetchProfile()
  }, [])

  const summary = useMemo(() => {
    const activeModules = modules.filter((module) => module.status === 'active').length
    return {
      activeModules,
      integrations: integrations.length,
      apiCalls: stats?.api_calls_30d ?? 0,
      lastLogin: stats?.last_login_at ? new Date(stats.last_login_at).toLocaleDateString() : '—',
    }
  }, [modules, integrations, stats])

  if (loading) {
    return <div className="text-sm text-white/60">Loading profile...</div>
  }

  if (error) {
    return (
      <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
        {error}
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold text-white">User Profile</h1>
        <p className="text-sm text-white/60">Manage your account settings and preferences</p>
      </div>

      <div className="grid gap-4 xl:grid-cols-[2fr,1fr]">
        <div className="space-y-4">
          <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
            <div className="flex items-center justify-between gap-4">
              <div>
                <h2 className="text-sm font-semibold">Profile Information</h2>
                <p className="text-xs text-white/60">Update your personal information and contact details</p>
              </div>
              <button
                type="button"
                className="rounded-full border border-white/10 px-4 py-2 text-xs font-semibold text-white/80 transition hover:bg-white/10"
              >
                Edit
              </button>
            </div>
            <div className="mt-4 grid gap-4 md:grid-cols-2">
              <div>
                <p className="text-[10px] uppercase tracking-wide text-white/40">Full Name</p>
                <div className="mt-1 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs">
                  {user?.name ?? '—'}
                </div>
              </div>
              <div>
                <p className="text-[10px] uppercase tracking-wide text-white/40">Email</p>
                <div className="mt-1 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs">
                  {user?.email ?? '—'}
                </div>
              </div>
            </div>
          </section>

          <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
            <div>
              <h2 className="text-sm font-semibold">Account Security</h2>
              <p className="text-xs text-white/60">Manage your account security settings</p>
            </div>
            <div className="mt-4 space-y-4 text-xs text-white/70">
              <div className="flex items-center justify-between border-b border-white/10 pb-4">
                <div>
                  <p className="text-sm font-semibold text-white">Password</p>
                  <p className="text-xs text-white/50">Last updated 30 days ago</p>
                </div>
                <button
                  type="button"
                  className="rounded-full border border-white/10 px-3 py-2 text-xs font-semibold text-white/80 transition hover:bg-white/10"
                >
                  Change Password
                </button>
              </div>
              <div className="flex items-center justify-between border-b border-white/10 pb-4">
                <div>
                  <p className="text-sm font-semibold text-white">Two-Factor Authentication</p>
                  <p className="text-xs text-white/50">Add an extra layer of security to your account</p>
                </div>
                <span className="rounded-full border border-white/10 px-3 py-1 text-[10px] text-white/60">
                  Not Enabled
                </span>
              </div>
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-semibold text-white">API Keys</p>
                  <p className="text-xs text-white/50">Manage your API access keys</p>
                </div>
                <button
                  type="button"
                  className="rounded-full border border-white/10 px-3 py-2 text-xs font-semibold text-white/80 transition hover:bg-white/10"
                >
                  Manage Keys
                </button>
              </div>
            </div>
          </section>

          <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
            <div>
              <h2 className="text-sm font-semibold">Preferences</h2>
              <p className="text-xs text-white/60">Customize your PortShield SaaS experience</p>
            </div>
            <div className="mt-4 space-y-4 text-xs text-white/70">
              <div className="flex items-center justify-between border-b border-white/10 pb-4">
                <div>
                  <p className="text-sm font-semibold text-white">Theme</p>
                  <p className="text-xs text-white/50">Choose your preferred theme</p>
                </div>
                <span className="rounded-full bg-sky-500/20 px-3 py-1 text-[10px] text-sky-200">
                  System Default
                </span>
              </div>
              <div className="flex items-center justify-between border-b border-white/10 pb-4">
                <div>
                  <p className="text-sm font-semibold text-white">Language</p>
                  <p className="text-xs text-white/50">Select your preferred language</p>
                </div>
                <span className="rounded-full bg-sky-500/20 px-3 py-1 text-[10px] text-sky-200">
                  English
                </span>
              </div>
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-semibold text-white">Notifications</p>
                  <p className="text-xs text-white/50">Configure your notification preferences</p>
                </div>
                <button
                  type="button"
                  className="rounded-full border border-white/10 px-3 py-2 text-xs font-semibold text-white/80 transition hover:bg-white/10"
                >
                  Configure
                </button>
              </div>
            </div>
          </section>
        </div>

        <div className="space-y-4">
          <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
            <h2 className="text-sm font-semibold">Account Summary</h2>
            <div className="mt-4 flex flex-col items-center gap-3 text-center">
              <div className="flex h-14 w-14 items-center justify-center rounded-full bg-white/5 text-xl">
                <span className="text-white/80">👤</span>
              </div>
              <div>
                <p className="text-sm font-semibold">{user?.name ?? '—'}</p>
                <p className="text-xs text-white/50">{user?.email ?? '—'}</p>
              </div>
              <div className="w-full border-t border-white/10 pt-3 text-left text-xs text-white/60">
                <div className="flex items-center justify-between">
                  <span>Email</span>
                  <span className="text-white/70">{user?.email ?? '—'}</span>
                </div>
                <div className="mt-2 flex items-center justify-between">
                  <span>Joined</span>
                  <span className="text-white/70">
                    {user?.created_at ? new Date(user.created_at).toLocaleDateString() : '—'}
                  </span>
                </div>
              </div>
            </div>
          </section>

          <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
            <h2 className="text-sm font-semibold">Account Stats</h2>
            <div className="mt-4 space-y-3 text-xs text-white/70">
              {[
                { label: 'Active Modules', value: String(summary.activeModules) },
                { label: 'Integrations', value: String(summary.integrations) },
                { label: 'API Calls (30d)', value: String(summary.apiCalls) },
                { label: 'Last Login', value: summary.lastLogin },
              ].map((stat) => (
                <div key={stat.label} className="flex items-center justify-between">
                  <span>{stat.label}</span>
                  <span className="rounded-full bg-sky-500/20 px-3 py-1 text-[10px] text-sky-200">
                    {stat.value}
                  </span>
                </div>
              ))}
            </div>
          </section>
        </div>
      </div>
    </div>
  )
}

export default Profile
