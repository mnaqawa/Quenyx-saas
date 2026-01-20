import { useEffect, useState } from 'react'
import { authService, AuthUser } from '../services/authService'
import { profileService, UserProfile } from '../services/profileService'
import { useLanguage } from '../i18n/LanguageContext'

function Profile() {
  const { t } = useLanguage()
  const [user, setUser] = useState<AuthUser | null>(null)
  const [profile, setProfile] = useState<UserProfile | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [editingName, setEditingName] = useState(false)
  const [nameValue, setNameValue] = useState('')
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    const fetchProfile = async () => {
      try {
        const [userData, profileResponse] = await Promise.all([
          authService.me(),
          profileService.getProfile(),
        ])
        setUser(userData)
        if (profileResponse.success) {
          setProfile(profileResponse.data)
          setNameValue(profileResponse.data.name)
        } else {
          setError(profileResponse.message || 'Failed to load profile')
        }
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load profile')
      } finally {
        setLoading(false)
      }
    }

    fetchProfile()
  }, [])

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

  return (
    <div className="space-y-6">
      <div className="space-y-1 text-center">
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
                onClick={() => {
                  if (editingName) {
                    setEditingName(false)
                    setNameValue(profile?.name || '')
                  } else {
                    setEditingName(true)
                    setNameValue(profile?.name || '')
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
                      onClick={async () => {
                        if (!nameValue.trim()) return
                        setSaving(true)
                        setError(null)
                        try {
                          const response = await profileService.updateProfile({ name: nameValue })
                          if (response.success) {
                            setProfile(response.data)
                            setUser({ ...user!, name: response.data.name })
                            setEditingName(false)
                          } else {
                            setError(response.message || 'Failed to update name')
                          }
                        } catch (err) {
                          setError(err instanceof Error ? err.message : 'Failed to update name')
                        } finally {
                          setSaving(false)
                        }
                      }}
                      disabled={saving || !nameValue.trim()}
                      className="rounded-lg bg-sky-500 px-3 py-2 text-xs font-semibold text-white transition hover:bg-sky-400 disabled:opacity-50"
                    >
                      {saving ? 'Saving...' : 'Save'}
                    </button>
                  </div>
                ) : (
                  <div className="mt-1 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs">
                    {profile?.name ?? user?.name ?? '—'}
                  </div>
                )}
              </div>
              <div>
                <p className="text-[10px] uppercase tracking-wide text-white/40">{t('profile.email')}</p>
                <div className="mt-1 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs">
                  {profile?.email ?? user?.email ?? '—'}
                </div>
              </div>
            </div>
            {error && editingName && (
              <div className="mt-2 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-xs text-rose-200">
                {error}
              </div>
            )}
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
                <p className="text-sm font-semibold">{profile?.name ?? user?.name ?? '—'}</p>
                <p className="text-xs text-white/50">{profile?.email ?? user?.email ?? '—'}</p>
              </div>
            </div>
          </section>
        </div>
      </div>
    </div>
  )
}

export default Profile

