import { useEffect, useMemo, useState } from 'react'
import { authService, AuthUser } from '../services/authService'
import { moduleService } from '../services/moduleService'
import { integrationService } from '../services/integrationService'
import { profileService, ProfileStats } from '../services/profileService'
import { useLanguage } from '../i18n/LanguageContext'
import type { Module } from '../services/dashboardService'
import type { Integration } from '../services/integrationService'

function Profile() {
  const { t } = useLanguage()
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
    return <div className="text-sm text-white/60">{t('common.loadingProfile')}</div>
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
        <h1 className="text-2xl font-semibold text-white">{t('profile.title')}</h1>
        <p className="text-sm text-white/60">{t('profile.subtitle')}</p>
      </div>

      <div className="grid gap-4 xl:grid-cols-[2fr,1fr]">
        <div className="space-y-4">
          <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
            <div className="flex items-center justify-between gap-4">
              <div>
                <h2 className="text-sm font-semibold">{t('profile.infoTitle')}</h2>
                <p className="text-xs text-white/60">{t('profile.infoDesc')}</p>
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
                <p className="text-[10px] uppercase tracking-wide text-white/40">{t('profile.fullName')}</p>
                <div className="mt-1 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs">
                  {user?.name ?? '—'}
                </div>
              </div>
              <div>
                <p className="text-[10px] uppercase tracking-wide text-white/40">{t('profile.email')}</p>
                <div className="mt-1 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs">
                  {user?.email ?? '—'}
                </div>
              </div>
            </div>
          </section>

          <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
            <div>
              <h2 className="text-sm font-semibold">{t('profile.accountSecurity')}</h2>
              <p className="text-xs text-white/60">{t('profile.accountSecurityDesc')}</p>
            </div>
            <div className="mt-4 space-y-4 text-xs text-white/70">
              <div className="flex items-center justify-between border-b border-white/10 pb-4">
                <div>
                  <p className="text-sm font-semibold text-white">{t('profile.password')}</p>
                  <p className="text-xs text-white/50">{t('profile.passwordUpdated')}</p>
                </div>
                <button
                  type="button"
                  className="rounded-full border border-white/10 px-3 py-2 text-xs font-semibold text-white/80 transition hover:bg-white/10"
                >
                  {t('profile.changePassword')}
                </button>
              </div>
              <div className="flex items-center justify-between border-b border-white/10 pb-4">
                <div>
                  <p className="text-sm font-semibold text-white">{t('profile.twoFactor')}</p>
                  <p className="text-xs text-white/50">{t('profile.twoFactorDesc')}</p>
                </div>
                <span className="rounded-full border border-white/10 px-3 py-1 text-[10px] text-white/60">
                  {t('profile.notEnabled')}
                </span>
              </div>
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-semibold text-white">{t('profile.apiKeys')}</p>
                  <p className="text-xs text-white/50">{t('profile.apiKeysDesc')}</p>
                </div>
                <button
                  type="button"
                  className="rounded-full border border-white/10 px-3 py-2 text-xs font-semibold text-white/80 transition hover:bg-white/10"
                >
                  {t('profile.manageKeys')}
                </button>
              </div>
            </div>
          </section>

          <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
            <div>
              <h2 className="text-sm font-semibold">{t('profile.preferences')}</h2>
              <p className="text-xs text-white/60">{t('profile.preferencesDesc')}</p>
            </div>
            <div className="mt-4 space-y-4 text-xs text-white/70">
              <div className="flex items-center justify-between border-b border-white/10 pb-4">
                <div>
                  <p className="text-sm font-semibold text-white">{t('profile.theme')}</p>
                  <p className="text-xs text-white/50">{t('profile.themeDesc')}</p>
                </div>
                <span className="rounded-full bg-sky-500/20 px-3 py-1 text-[10px] text-sky-200">
                  {t('profile.systemDefault')}
                </span>
              </div>
              <div className="flex items-center justify-between border-b border-white/10 pb-4">
                <div>
                  <p className="text-sm font-semibold text-white">{t('profile.language')}</p>
                  <p className="text-xs text-white/50">{t('profile.languageDesc')}</p>
                </div>
                <span className="rounded-full bg-sky-500/20 px-3 py-1 text-[10px] text-sky-200">
                  {t('language.english')}
                </span>
              </div>
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm font-semibold text-white">{t('profile.notifications')}</p>
                  <p className="text-xs text-white/50">{t('profile.notificationsDesc')}</p>
                </div>
                <button
                  type="button"
                  className="rounded-full border border-white/10 px-3 py-2 text-xs font-semibold text-white/80 transition hover:bg-white/10"
                >
                  {t('profile.configure')}
                </button>
              </div>
            </div>
          </section>
        </div>

        <div className="space-y-4">
          <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
            <h2 className="text-sm font-semibold">{t('profile.accountSummary')}</h2>
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
                  <span>{t('profile.joined')}</span>
                  <span className="text-white/70">
                    {user?.created_at ? new Date(user.created_at).toLocaleDateString() : '—'}
                  </span>
                </div>
              </div>
            </div>
          </section>

          <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
            <h2 className="text-sm font-semibold">{t('profile.accountStats')}</h2>
            <div className="mt-4 space-y-3 text-xs text-white/70">
              {[
                { label: t('profile.activeModules'), value: String(summary.activeModules) },
                { label: t('profile.integrations'), value: String(summary.integrations) },
                { label: t('profile.apiCalls'), value: String(summary.apiCalls) },
                { label: t('profile.lastLogin'), value: summary.lastLogin },
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
