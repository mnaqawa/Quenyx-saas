import { useEffect, useMemo, useState } from 'react'
import { Module } from '../services/dashboardService'
import { moduleService } from '../services/moduleService'
import { useLanguage } from '../i18n/LanguageContext'

const statusLabels: Record<Module['subscription_state'], string> = {
  active: 'Subscribed',
  inactive: 'Not Subscribed',
  trial: 'Trial',
  expired: 'Expired',
}

const subscriptionOrder = [
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
]

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

  const { orderedModules, coreModule } = useMemo(() => {
    const map = new Map(modules.map((module) => [module.name, module]))
    const core = map.get('ShieldCore') ?? null
    const ordered = subscriptionOrder
      .map((name) => map.get(name))
      .filter((module): module is Module => Boolean(module))
    return { orderedModules: ordered, coreModule: core }
  }, [modules])

  return (
    <div className="space-y-6">
      <div className="space-y-1 text-center">
        <h1 className="text-2xl font-semibold text-white">{t('subscriptions.title')}</h1>
        <p className="text-sm text-white/60">{t('subscriptions.subtitle')}</p>
      </div>

      {coreModule ? (
        <div className="rounded-2xl border border-sky-500/40 bg-gradient-to-r from-sky-500/10 via-slate-900/40 to-slate-900/10 p-5 text-white">
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div className="flex items-center gap-3">
              <div className="flex h-12 w-12 items-center justify-center rounded-full bg-sky-500/20">
                <span className="text-xs font-semibold text-sky-100">SC</span>
              </div>
              <div>
                <h3 className="text-lg font-semibold">{coreModule.name}</h3>
                <p className="text-xs text-sky-200">{statusLabels[coreModule.subscription_state]}</p>
              </div>
            </div>
            <span className="rounded-full border border-sky-500/30 bg-sky-500/10 px-3 py-1 text-[10px] font-semibold text-sky-100">
              Core Module
            </span>
          </div>
          <p className="mt-4 text-sm text-white/70">
            {coreModule.description || t('subscriptions.noDescription')}
          </p>
          <div className="mt-4 flex items-center gap-3 text-xs text-white/60">
            <span>Status: {coreModule.status}</span>
            <span>Subscription: {statusLabels[coreModule.subscription_state]}</span>
          </div>
          <button
            type="button"
            className="mt-4 rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-400"
          >
            {t('subscriptions.viewPlans')}
          </button>
        </div>
      ) : null}

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {orderedModules.map((module) => (
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
