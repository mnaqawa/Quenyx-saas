import { useEffect, useState } from 'react'
import { Module } from '../services/dashboardService'
import { moduleService } from '../services/moduleService'
import { useLanguage } from '../i18n/LanguageContext'

const statusLabels: Record<Module['subscription_state'], string> = {
  active: 'Subscribed',
  inactive: 'Not Subscribed',
  trial: 'Trial',
  expired: 'Expired',
}

function Subscriptions() {
  const { t } = useLanguage()
  const [modules, setModules] = useState<Module[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const fetchModules = async () => {
      try {
        const data = await moduleService.getModules()
        setModules(data)
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load subscriptions')
      } finally {
        setLoading(false)
      }
    }

    fetchModules()
  }, [])

  if (loading) {
    return <div className="text-sm text-white/60">{t('common.loadingSubscriptions')}</div>
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
      <div className="space-y-1 text-center">
        <h1 className="text-2xl font-semibold text-white">{t('subscriptions.title')}</h1>
        <p className="text-sm text-white/60">{t('subscriptions.subtitle')}</p>
      </div>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {modules.map((module) => (
          <div
            key={module.id}
            className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white"
          >
            <div className="flex items-start justify-between gap-4">
              <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-white/5">
                  <span className="text-xs font-semibold text-white/70">
                    {module.name.slice(0, 2)}
                  </span>
                </div>
                <div>
                  <h3 className="text-sm font-semibold">{module.name}</h3>
                  <p className="text-xs text-white/50">{statusLabels[module.subscription_state]}</p>
                </div>
              </div>
              <span className="rounded-full border border-white/10 px-3 py-1 text-[10px] text-white/60">
                {statusLabels[module.subscription_state]}
              </span>
            </div>

            <p className="mt-4 text-xs leading-relaxed text-white/60">
              {module.description || t('subscriptions.noDescription')}
            </p>
            <p className="mt-4 text-[11px] text-white/40">Subscribe to access module insights and features</p>

            <button
              type="button"
              className="mt-5 inline-flex items-center justify-center rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-400"
            >
              {t('subscriptions.viewPlans')}
            </button>
          </div>
        ))}
      </div>
    </div>
  )
}

export default Subscriptions
