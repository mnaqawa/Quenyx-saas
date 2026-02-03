import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { profileService, UserProfile, UserProfilePreferences } from '../services/profileService'
import { useLanguage } from '../i18n/LanguageContext'
import type { Language } from '../i18n/translations'

function formatRelativeDate(dateStr: string | null | undefined): string {
  if (!dateStr) return '—'
  const d = new Date(dateStr)
  if (isNaN(d.getTime())) return '—'
  const now = new Date()
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const other = new Date(d.getFullYear(), d.getMonth(), d.getDate())
  const diffDays = Math.floor((today.getTime() - other.getTime()) / (24 * 60 * 60 * 1000))
  if (diffDays === 0) return 'Today'
  if (diffDays === 1) return 'Yesterday'
  if (diffDays < 7) return `${diffDays} days ago`
  return d.toLocaleDateString()
}

function formatNumber(n: number): string {
  if (n >= 1000) return `${(n / 1000).toFixed(1)}K`
  return String(n)
}

export default function Profile() {
  const { t, language, setLanguage } = useLanguage()
  const [profile, setProfile] = useState<UserProfile | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [editingName, setEditingName] = useState(false)
  const [nameValue, setNameValue] = useState('')
  const [saving, setSaving] = useState(false)
  const [passwordModalOpen, setPasswordModalOpen] = useState(false)
  const [passwordCurrent, setPasswordCurrent] = useState('')
  const [passwordNew, setPasswordNew] = useState('')
  const [passwordConfirm, setPasswordConfirm] = useState('')
  const [passwordError, setPasswordError] = useState<string | null>(null)
  const [passwordSaving, setPasswordSaving] = useState(false)

  const fetchProfile = async () => {
    try {
      const data = await profileService.getProfile()
      setProfile(data)
      setNameValue(data.name)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load profile')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    fetchProfile()
  }, [])

  // Apply saved preferences when profile loads (theme + language); fallback to localStorage for theme
  useEffect(() => {
    const prefs = profile?.preferences
    const prefLang = prefs?.language
    const theme = prefs?.theme ?? (typeof localStorage !== 'undefined' ? localStorage.getItem('portshield.theme') : null)
    if (prefLang === 'en' || prefLang === 'ar') {
      setLanguage(prefLang)
    }
    if (theme === 'light' || theme === 'dark') {
      document.documentElement.classList.remove('light', 'dark')
      document.documentElement.classList.add(theme)
      if (typeof localStorage !== 'undefined') localStorage.setItem('portshield.theme', theme)
    } else if (theme === 'system') {
      document.documentElement.classList.remove('light', 'dark')
      if (typeof localStorage !== 'undefined') localStorage.setItem('portshield.theme', 'system')
    }
  }, [profile?.preferences, setLanguage])

  const handleSaveName = async () => {
    if (!nameValue.trim()) return
    setSaving(true)
    setError(null)
    try {
      const updated = await profileService.updateProfile({ name: nameValue })
      setProfile(updated)
      setEditingName(false)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update name')
    } finally {
      setSaving(false)
    }
  }

  const handleChangePassword = async () => {
    setPasswordError(null)
    if (!passwordCurrent || !passwordNew || !passwordConfirm) {
      setPasswordError('All fields are required.')
      return
    }
    if (passwordNew.length < 8) {
      setPasswordError('New password must be at least 8 characters.')
      return
    }
    if (passwordNew !== passwordConfirm) {
      setPasswordError('New password and confirmation do not match.')
      return
    }
    setPasswordSaving(true)
    try {
      await profileService.changePassword(passwordCurrent, passwordNew)
      setPasswordModalOpen(false)
      setPasswordCurrent('')
      setPasswordNew('')
      setPasswordConfirm('')
    } catch (err) {
      setPasswordError(err instanceof Error ? err.message : 'Failed to change password')
    } finally {
      setPasswordSaving(false)
    }
  }

  const handleThemeChange = async (theme: 'light' | 'dark' | 'system') => {
    const prefs: UserProfilePreferences = { ...profile?.preferences, theme }
    try {
      const updated = await profileService.updateProfile({ preferences: prefs })
      setProfile(updated)
      if (theme === 'system') {
        document.documentElement.classList.remove('light', 'dark')
      } else {
        document.documentElement.classList.remove('light', 'dark')
        document.documentElement.classList.add(theme)
      }
    } catch {
      // ignore
    }
  }

  const handleLanguageChange = async (lang: Language) => {
    setLanguage(lang)
    const prefs: UserProfilePreferences = { ...profile?.preferences, language: lang }
    try {
      const updated = await profileService.updateProfile({ preferences: prefs })
      setProfile(updated)
    } catch {
      // ignore
    }
  }

  if (loading) {
    return <div className="text-sm text-white/60">{t('common.loadingProfile')}</div>
  }

  if (error && !profile) {
    return (
      <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
        {error}
      </div>
    )
  }

  const stats = profile?.stats ?? { active_modules: 0, integrations: 0, api_calls_30d: 0 }
  const theme = profile?.preferences?.theme ?? (typeof localStorage !== 'undefined' ? localStorage.getItem('portshield.theme') : null) ?? 'system'

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold text-white">{t('profile.title')}</h1>
        <p className="text-sm text-white/60">{t('profile.subtitle')}</p>
      </div>

      <div className="grid gap-6 xl:grid-cols-[2fr,1fr]">
        <div className="space-y-6">
          {/* Profile Information */}
          <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
            <div className="flex items-center justify-between gap-4">
              <div className="flex items-center gap-2">
                <span className="text-lg" aria-hidden>👤</span>
                <div>
                  <h2 className="text-sm font-semibold">{t('profile.infoTitle')}</h2>
                  <p className="text-xs text-white/60">{t('profile.infoDesc')}</p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => {
                  if (editingName) {
                    setEditingName(false)
                    setNameValue(profile?.name ?? '')
                  } else {
                    setEditingName(true)
                    setNameValue(profile?.name ?? '')
                  }
                }}
                className="rounded-full border border-white/10 px-4 py-2 text-xs font-semibold text-white/80 transition hover:bg-white/10"
              >
                {editingName ? 'Cancel' : 'Edit'}
              </button>
            </div>
            <div className="mt-4 grid gap-4 md:grid-cols-2">
              <div>
                <p className="text-[10px] uppercase tracking-wide text-white/40">{t('profile.fullName')}</p>
                {editingName ? (
                  <div className="mt-1 flex gap-2">
                    <input
                      type="text"
                      value={nameValue}
                      onChange={(e) => setNameValue(e.target.value)}
                      className="flex-1 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs text-white focus:border-sky-500 focus:outline-none"
                    />
                    <button
                      type="button"
                      onClick={handleSaveName}
                      disabled={saving || !nameValue.trim()}
                      className="rounded-lg bg-sky-500 px-3 py-2 text-xs font-semibold text-white transition hover:bg-sky-400 disabled:opacity-50"
                    >
                      {saving ? 'Saving...' : 'Save'}
                    </button>
                  </div>
                ) : (
                  <div className="mt-1 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs">
                    {profile?.name ?? '—'}
                  </div>
                )}
              </div>
              <div>
                <p className="text-[10px] uppercase tracking-wide text-white/40">{t('profile.email')}</p>
                <div className="mt-1 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs">
                  {profile?.email ?? '—'}
                </div>
              </div>
            </div>
            {error && editingName && (
              <div className="mt-2 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-200">
                {error}
              </div>
            )}
          </section>

          {/* Account Security */}
          <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
            <div className="flex items-center gap-2">
              <span className="text-lg" aria-hidden>🛡️</span>
              <div>
                <h2 className="text-sm font-semibold">{t('profile.accountSecurity')}</h2>
                <p className="text-xs text-white/60">{t('profile.accountSecurityDesc')}</p>
              </div>
            </div>
            <div className="mt-4 space-y-4">
              <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-white/5 bg-white/5 px-4 py-3">
                <div>
                  <p className="text-xs font-medium">{t('profile.password')}</p>
                  <p className="text-[10px] text-white/50">{t('profile.passwordUpdated')}</p>
                </div>
                <button
                  type="button"
                  onClick={() => setPasswordModalOpen(true)}
                  className="rounded-lg border border-white/10 px-3 py-1.5 text-xs font-medium text-white/90 hover:bg-white/10"
                >
                  {t('profile.changePassword')}
                </button>
              </div>
              <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-white/5 bg-white/5 px-4 py-3">
                <div>
                  <p className="text-xs font-medium">{t('profile.twoFactor')}</p>
                  <p className="text-[10px] text-white/50">{t('profile.twoFactorDesc')}</p>
                </div>
                <span className="rounded-lg border border-amber-500/30 px-3 py-1.5 text-xs text-amber-200">
                  {t('profile.notEnabled')}
                </span>
              </div>
              <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-white/5 bg-white/5 px-4 py-3">
                <div>
                  <p className="text-xs font-medium">{t('profile.apiKeys')}</p>
                  <p className="text-[10px] text-white/50">{t('profile.apiKeysDesc')}</p>
                </div>
                <Link
                  to="/integrations"
                  className="inline-flex items-center gap-1 rounded-lg border border-white/10 px-3 py-1.5 text-xs font-medium text-white/90 hover:bg-white/10"
                >
                  <span aria-hidden>🔑</span>
                  {t('profile.manageKeys')}
                </Link>
              </div>
            </div>
          </section>

          {/* Preferences */}
          <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
            <div className="flex items-center gap-2">
              <span className="text-lg" aria-hidden>⚙️</span>
              <div>
                <h2 className="text-sm font-semibold">{t('profile.preferences')}</h2>
                <p className="text-xs text-white/60">{t('profile.preferencesDesc')}</p>
              </div>
            </div>
            <div className="mt-4 space-y-4">
              <div>
                <p className="text-[10px] uppercase tracking-wide text-white/40">{t('profile.theme')}</p>
                <p className="text-[10px] text-white/50">{t('profile.themeDesc')}</p>
                <div className="mt-2 flex flex-wrap gap-2">
                  {(['system', 'light', 'dark'] as const).map((value) => (
                    <button
                      key={value}
                      type="button"
                      onClick={() => handleThemeChange(value)}
                      className={`rounded-lg border px-3 py-1.5 text-xs capitalize ${
                        theme === value
                          ? 'border-sky-500 bg-sky-500/20 text-sky-200'
                          : 'border-white/10 text-white/80 hover:bg-white/10'
                      }`}
                    >
                      {value === 'system' ? t('profile.systemDefault') : value}
                    </button>
                  ))}
                </div>
              </div>
              <div>
                <p className="text-[10px] uppercase tracking-wide text-white/40">{t('profile.language')}</p>
                <p className="text-[10px] text-white/50">{t('profile.languageDesc')}</p>
                <div className="mt-2 flex flex-wrap gap-2">
                  <button
                    type="button"
                    onClick={() => handleLanguageChange('en')}
                    className={`inline-flex items-center gap-1 rounded-lg border px-3 py-1.5 text-xs ${
                      language === 'en' ? 'border-sky-500 bg-sky-500/20 text-sky-200' : 'border-white/10 text-white/80 hover:bg-white/10'
                    }`}
                  >
                    <span aria-hidden>🌐</span>
                    English
                  </button>
                  <button
                    type="button"
                    onClick={() => handleLanguageChange('ar')}
                    className={`inline-flex items-center gap-1 rounded-lg border px-3 py-1.5 text-xs ${
                      language === 'ar' ? 'border-sky-500 bg-sky-500/20 text-sky-200' : 'border-white/10 text-white/80 hover:bg-white/10'
                    }`}
                  >
                    <span aria-hidden>🌐</span>
                    Arabic
                  </button>
                </div>
              </div>
              <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-white/5 bg-white/5 px-4 py-3">
                <div>
                  <p className="text-xs font-medium">{t('profile.notifications')}</p>
                  <p className="text-[10px] text-white/50">{t('profile.notificationsDesc')}</p>
                </div>
                <button
                  type="button"
                  className="inline-flex items-center gap-1 rounded-lg border border-white/10 px-3 py-1.5 text-xs font-medium text-white/80 hover:bg-white/10"
                  title="Coming soon"
                >
                  <span aria-hidden>🔔</span>
                  {t('profile.configure')}
                </button>
              </div>
            </div>
          </section>
        </div>

        <div className="space-y-6">
          {/* Account Summary */}
          <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
            <h2 className="text-sm font-semibold">{t('profile.accountSummary')}</h2>
            <div className="mt-4 flex flex-col items-center gap-3 text-center">
              <div className="flex h-16 w-16 items-center justify-center rounded-full bg-white/10 text-2xl">
                <span className="text-white/80" aria-hidden>👤</span>
              </div>
              <div>
                <p className="text-sm font-semibold">{profile?.name ?? '—'}</p>
                <p className="text-xs text-white/50">{profile?.email ?? '—'}</p>
              </div>
              {profile?.created_at && (
                <p className="inline-flex items-center gap-1 text-[10px] text-white/40">
                  <span aria-hidden>📅</span>
                  {t('profile.joined')} {new Date(profile.created_at).toLocaleDateString()}
                </p>
              )}
            </div>
          </section>

          {/* Account Stats */}
          <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
            <h2 className="text-sm font-semibold">{t('profile.accountStats')}</h2>
            <div className="mt-4 space-y-3">
              <div className="flex justify-between text-xs">
                <span className="text-white/60">{t('profile.activeModules')}</span>
                <span className="rounded-full bg-sky-500/20 px-2 py-0.5 font-medium text-sky-200">
                  {stats.active_modules}
                </span>
              </div>
              <div className="flex justify-between text-xs">
                <span className="text-white/60">{t('profile.integrations')}</span>
                <span className="rounded-full bg-sky-500/20 px-2 py-0.5 font-medium text-sky-200">
                  {stats.integrations}
                </span>
              </div>
              <div className="flex justify-between text-xs">
                <span className="text-white/60">{t('profile.apiCalls')}</span>
                <span className="rounded-full bg-sky-500/20 px-2 py-0.5 font-medium text-sky-200">
                  {formatNumber(stats.api_calls_30d)}
                </span>
              </div>
              <div className="flex justify-between text-xs">
                <span className="text-white/60">{t('profile.lastLogin')}</span>
                <span className="text-white/80">{formatRelativeDate(profile?.last_login_at)}</span>
              </div>
            </div>
          </section>
        </div>
      </div>

      {/* Change Password Modal */}
      {passwordModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
          <div className="w-full max-w-md rounded-2xl border border-white/10 bg-[#0f151d] p-6 text-white shadow-xl">
            <h3 className="text-lg font-semibold">{t('profile.changePassword')}</h3>
            <div className="mt-4 space-y-3">
              <div>
                <label className="block text-[10px] uppercase tracking-wide text-white/40">Current password</label>
                <input
                  type="password"
                  value={passwordCurrent}
                  onChange={(e) => setPasswordCurrent(e.target.value)}
                  className="mt-1 w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white focus:border-sky-500 focus:outline-none"
                  autoComplete="current-password"
                />
              </div>
              <div>
                <label className="block text-[10px] uppercase tracking-wide text-white/40">New password</label>
                <input
                  type="password"
                  value={passwordNew}
                  onChange={(e) => setPasswordNew(e.target.value)}
                  className="mt-1 w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white focus:border-sky-500 focus:outline-none"
                  autoComplete="new-password"
                />
              </div>
              <div>
                <label className="block text-[10px] uppercase tracking-wide text-white/40">Confirm new password</label>
                <input
                  type="password"
                  value={passwordConfirm}
                  onChange={(e) => setPasswordConfirm(e.target.value)}
                  className="mt-1 w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white focus:border-sky-500 focus:outline-none"
                  autoComplete="new-password"
                />
              </div>
            </div>
            {passwordError && (
              <div className="mt-3 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-200">
                {passwordError}
              </div>
            )}
            <div className="mt-6 flex justify-end gap-2">
              <button
                type="button"
                onClick={() => {
                  setPasswordModalOpen(false)
                  setPasswordError(null)
                  setPasswordCurrent('')
                  setPasswordNew('')
                  setPasswordConfirm('')
                }}
                className="rounded-lg border border-white/10 px-4 py-2 text-sm font-medium text-white/80 hover:bg-white/10"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={handleChangePassword}
                disabled={passwordSaving}
                className="rounded-lg bg-sky-500 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-400 disabled:opacity-50"
              >
                {passwordSaving ? 'Updating...' : 'Update password'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
