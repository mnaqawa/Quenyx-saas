import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { subscriptionService, Plan } from '../services/subscriptionService'
import { moduleService } from '../services/moduleService'
import { useLanguage } from '../i18n/LanguageContext'
import { useProjectContext } from '../projects/ProjectContext'
import { PlanKey } from '../types/subscription'

function Subscriptions() {
  const { t } = useLanguage()
  const [searchParams, setSearchParams] = useSearchParams()
  const { selectedProjectId, entitlements, refreshEntitlements, modulesWithAccess, isLoadingModules, modulesError, refreshModules } = useProjectContext()
  const [subscription, setSubscription] = useState<any>(null)
  const [loadingSubscription, setLoadingSubscription] = useState(false)
  const [savingPlan, setSavingPlan] = useState(false)
  const [plans, setPlans] = useState<Plan[]>([])
  const [highlightedModule, setHighlightedModule] = useState<string | null>(null)

  const targetModuleKey = searchParams.get('module')

  const { coreModule, otherModules, recommendedPlan } = useMemo(() => {
    if (!modulesWithAccess) {
      return { coreModule: null, otherModules: [], recommendedPlan: null }
    }
    // Deduplicate modules by key first
    const uniqueModules = modulesWithAccess.filter((module, index, self) => 
      module.key && index === self.findIndex((m) => m.key === module.key)
    )
    const core = uniqueModules.find((m) => m.key === 'shieldcore') ?? null
    const others = uniqueModules.filter((m) => m.key !== 'shieldcore')

    // Determine recommended plan for target module (based on allowed_by_plan, not override)
    let recommended: PlanKey | null = null
    if (targetModuleKey && plans.length > 0) {
      const targetModule = uniqueModules.find((m) => m.key === targetModuleKey)
      if (targetModule && !targetModule.allowed_by_plan) {
        // Find the lowest plan that includes this module
        const planOrder: PlanKey[] = ['free', 'pro', 'enterprise']
        for (const planKey of planOrder) {
          const plan = plans.find((p) => p.key === planKey)
          if (plan && plan.features.modules.includes(targetModuleKey)) {
            recommended = planKey
            break
          }
        }
      }
    }

    return { coreModule: core, otherModules: others, recommendedPlan: recommended }
  }, [modulesWithAccess, targetModuleKey, plans])


  useEffect(() => {
    const fetchSubscription = async () => {
      if (!selectedProjectId) {
        setSubscription(null)
        return
      }
      setLoadingSubscription(true)
      try {
        const response = await subscriptionService.getProjectSubscription(selectedProjectId)
        if (response.success) {
          setSubscription(response.data)
        }
      } catch (err) {
        // Ignore errors for now
      } finally {
        setLoadingSubscription(false)
      }
    }

    fetchSubscription()
  }, [selectedProjectId])

  useEffect(() => {
    const fetchPlans = async () => {
      try {
        const response = await subscriptionService.getPlans()
        if (response.success) {
          setPlans(response.data)
        }
      } catch (err) {
        // Ignore errors
      }
    }

    fetchPlans()
  }, [])

  // Scroll to and highlight target module
  useEffect(() => {
    if (targetModuleKey && modulesWithAccess && !isLoadingModules) {
      // Small delay to ensure DOM is ready
      setTimeout(() => {
        const element = document.getElementById(`module-card-${targetModuleKey}`)
        if (element) {
          element.scrollIntoView({ behavior: 'smooth', block: 'center' })
          setHighlightedModule(targetModuleKey)
          // Remove highlight after 3 seconds
          setTimeout(() => {
            setHighlightedModule(null)
            // Remove query param after highlighting
            setSearchParams((prev) => {
              const newParams = new URLSearchParams(prev)
              newParams.delete('module')
              return newParams
            })
          }, 3000)
        }
      }, 100)
    }
  }, [targetModuleKey, modulesWithAccess, isLoadingModules, setSearchParams])

  const handlePlanChange = async (planKey: PlanKey) => {
    if (!selectedProjectId || savingPlan) return

    setSavingPlan(true)
    try {
      const response = await subscriptionService.updateProjectSubscription(selectedProjectId, planKey)
      if (response.success) {
        setSubscription(response.data)
        await refreshEntitlements()
        await refreshModules()
      }
    } catch (err) {
      // Handle error
    } finally {
      setSavingPlan(false)
    }
  }

  const handleOverrideChange = async (moduleKey: string, mode: 'allow' | 'deny' | null) => {
    if (!selectedProjectId) return

    try {
      await moduleService.updateModuleOverride(selectedProjectId, moduleKey, mode)
      await refreshModules()
      await refreshEntitlements()
    } catch (err) {
      // Handle error
    }
  }

  if (!selectedProjectId) {
    return (
      <div className="space-y-6">
        <div className="space-y-1 text-center">
          <h1 className="text-2xl font-semibold text-white">{t('subscriptions.title')}</h1>
          <p className="text-sm text-white/60">{t('subscriptions.subtitle')}</p>
        </div>
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-10 text-center text-white">
          <p className="text-sm text-white/60">Select a project to view module subscriptions</p>
        </div>
      </div>
    )
  }

  if (isLoadingModules) {
    return <div className="text-sm text-white/60">{t('common.loadingSubscriptions')}</div>
  }

  return (
    <div className="space-y-6">
      <div className="space-y-1 text-center">
        <h1 className="text-2xl font-semibold text-white">{t('subscriptions.title')}</h1>
        <p className="text-sm text-white/60">{t('subscriptions.subtitle')}</p>
      </div>

      {modulesError && (
        <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
          Failed to load modules: {modulesError}
        </div>
      )}

      {selectedProjectId && (
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <h2 className="text-sm font-semibold mb-4">Project Subscription</h2>
          {loadingSubscription ? (
            <div className="text-sm text-white/60">Loading...</div>
          ) : subscription ? (
            <div className="space-y-4">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-xs text-white/50">Current Plan</p>
                  <p className="text-lg font-semibold">{subscription.plan.name}</p>
                  <p className="text-xs text-white/60">Status: {subscription.status}</p>
                </div>
                <div>
                  <p className="text-xs text-white/50">Allowed Modules</p>
                  <p className="text-lg font-semibold">
                    {entitlements?.modules_allowed.length ?? 0}
                  </p>
                </div>
              </div>
              <div>
                <p className="text-xs text-white/50 mb-2">Switch Plan</p>
                {targetModuleKey && recommendedPlan && (
                  <div className="mb-3 rounded-lg border border-sky-500/30 bg-sky-500/10 px-3 py-2 text-xs text-sky-200">
                    Recommended plan: <span className="font-semibold">{recommendedPlan.charAt(0).toUpperCase() + recommendedPlan.slice(1)}</span> (unlocks {modulesWithAccess?.find((m) => m.key === targetModuleKey)?.name || targetModuleKey})
                  </div>
                )}
                <div className="flex gap-2">
                  {(['free', 'pro', 'enterprise'] as PlanKey[]).map((planKey) => (
                    <button
                      key={planKey}
                      type="button"
                      onClick={() => handlePlanChange(planKey)}
                      disabled={savingPlan || subscription.plan.key === planKey}
                      className={`rounded-full px-4 py-2 text-xs font-semibold transition ${
                        subscription.plan.key === planKey
                          ? 'bg-sky-500 text-white'
                          : recommendedPlan === planKey
                          ? 'border-2 border-sky-400 bg-sky-500/20 text-sky-200'
                          : 'border border-white/10 bg-white/5 text-white/70 hover:bg-white/10'
                      } disabled:opacity-50 disabled:cursor-not-allowed`}
                    >
                      {planKey.charAt(0).toUpperCase() + planKey.slice(1)}
                    </button>
                  ))}
                </div>
                {savingPlan && (
                  <p className="mt-2 text-xs text-white/60">Saving...</p>
                )}
              </div>
            </div>
          ) : (
            <div className="text-sm text-white/60">No subscription found</div>
          )}
        </div>
      )}

      {coreModule ? (
        <div
          id={`module-card-${coreModule.key}`}
          className={`rounded-2xl border border-sky-500/40 bg-gradient-to-r from-sky-500/10 via-slate-900/40 to-slate-900/10 p-5 text-white transition-all duration-300 ${
            highlightedModule === coreModule.key ? 'ring-4 ring-sky-400 ring-opacity-50 shadow-lg shadow-sky-500/20' : ''
          }`}
        >
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div className="flex items-center gap-3">
              <div className="flex h-12 w-12 items-center justify-center rounded-full bg-sky-500/20">
                <span className="text-xs font-semibold text-sky-100">SC</span>
              </div>
              <div>
                <h3 className="text-lg font-semibold">{coreModule.name}</h3>
                <p className={`text-xs ${coreModule.allowed ? 'text-sky-200' : 'text-white/50'}`}>
                  {coreModule.allowed ? 'Included' : 'Not Included'}
                </p>
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
            <span className={`font-semibold ${coreModule.allowed ? 'text-emerald-200' : 'text-white/50'}`}>
              Access: {coreModule.allowed ? 'Included' : 'Not Included'}
            </span>
            {coreModule.allowed_by_plan && !coreModule.override && (
              <span className="rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2 py-0.5 text-[10px] text-emerald-200">
                Included (Plan)
              </span>
            )}
            {coreModule.override === 'allow' && (
              <span className="rounded-full border border-sky-500/30 bg-sky-500/10 px-2 py-0.5 text-[10px] text-sky-200">
                Override: Enabled
              </span>
            )}
            {coreModule.override === 'deny' && (
              <span className="rounded-full border border-rose-500/30 bg-rose-500/10 px-2 py-0.5 text-[10px] text-rose-200">
                Override: Disabled
              </span>
            )}
          </div>
          <div className="mt-4 flex items-center gap-3">
            <label className="text-xs text-white/60">Override:</label>
            <select
              value={coreModule.override || ''}
              onChange={(e) => {
                const value = e.target.value
                handleOverrideChange(coreModule.key, value === '' ? null : value as 'allow' | 'deny')
              }}
              className="rounded-md border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white focus:border-sky-500 focus:outline-none"
            >
              <option value="">Default (Plan)</option>
              <option value="allow">Force Enable</option>
              <option value="deny">Force Disable</option>
            </select>
          </div>
        </div>
      ) : null}

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {otherModules.map((module) => (
          <div
            id={`module-card-${module.key}`}
            key={module.key}
            className={`rounded-2xl border p-5 text-white transition-all duration-300 ${
              module.allowed
                ? 'border-white/10 bg-[#0f151d]'
                : 'border-white/5 bg-[#0f151d] opacity-60'
            } ${
              highlightedModule === module.key ? 'ring-4 ring-sky-400 ring-opacity-50 shadow-lg shadow-sky-500/20' : ''
            }`}
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
                  <p className={`text-xs ${module.allowed ? 'text-emerald-200' : 'text-white/50'}`}>
                    {module.allowed ? 'Included' : 'Not Included'}
                  </p>
                </div>
              </div>
              <div className="flex flex-col items-end gap-2">
                <span
                  className={`rounded-full border px-3 py-1 text-[10px] ${
                    module.allowed
                      ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-200'
                      : 'border-white/10 text-white/40'
                  }`}
                >
                  {module.allowed ? 'Included' : 'Not Included'}
                </span>
                {module.allowed_by_plan && !module.override && (
                  <span className="rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2 py-0.5 text-[10px] text-emerald-200">
                    Included (Plan)
                  </span>
                )}
                {module.override === 'allow' && (
                  <span className="rounded-full border border-sky-500/30 bg-sky-500/10 px-2 py-0.5 text-[10px] text-sky-200">
                    Override: Enabled
                  </span>
                )}
                {module.override === 'deny' && (
                  <span className="rounded-full border border-rose-500/30 bg-rose-500/10 px-2 py-0.5 text-[10px] text-rose-200">
                    Override: Disabled
                  </span>
                )}
              </div>
            </div>

            <p className="mt-4 text-xs leading-relaxed text-white/60">
              {module.description || t('subscriptions.noDescription')}
            </p>

            <div className="mt-4 flex items-center gap-3">
              <label className="text-xs text-white/60">Override:</label>
              <select
                value={module.override || ''}
                onChange={(e) => {
                  const value = e.target.value
                  handleOverrideChange(module.key, value === '' ? null : value as 'allow' | 'deny')
                }}
                className="rounded-md border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white focus:border-sky-500 focus:outline-none"
              >
                <option value="">Default (Plan)</option>
                <option value="allow">Force Enable</option>
                <option value="deny">Force Disable</option>
              </select>
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}

export default Subscriptions
