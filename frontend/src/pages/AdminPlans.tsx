import { useEffect, useState } from 'react'
import { planService, Plan } from '../services/planService'
import { moduleService } from '../services/moduleService'

function AdminPlans() {
  const [plans, setPlans] = useState<Plan[]>([])
  const [modules, setModules] = useState<Array<{ key: string; name: string }>>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [editingPlan, setEditingPlan] = useState<Plan | null>(null)
  const [formData, setFormData] = useState({
    key: '',
    name: '',
    price_cents: 0,
    interval: 'month' as 'month' | 'year' | null,
    modules: [] as string[],
  })
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    const fetchData = async () => {
      try {
        const [plansResponse, modulesResponse] = await Promise.all([
          planService.getPlans(),
          moduleService.getModulesCatalog(),
        ])

        if (!plansResponse.success) {
          setError(plansResponse.message || 'Failed to load plans')
          return
        }

        if (!modulesResponse.success) {
          setError(modulesResponse.message || 'Failed to load modules')
          return
        }

        setPlans(plansResponse.data)
        setModules(modulesResponse.data.map((m) => ({ key: m.key, name: m.name })))
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load data')
      } finally {
        setLoading(false)
      }
    }

    fetchData()
  }, [])

  const handleCreate = () => {
    setEditingPlan(null)
    setFormData({
      key: '',
      name: '',
      price_cents: 0,
      interval: 'month',
      modules: [],
    })
  }

  const handleEdit = (plan: Plan) => {
    setEditingPlan(plan)
    setFormData({
      key: plan.key,
      name: plan.name,
      price_cents: plan.price_cents,
      interval: (plan.interval as 'month' | 'year' | null) || null,
      modules: plan.features.modules_allowed || plan.features.modules || [],
    })
  }

  const handleSave = async () => {
    if (!formData.key || !formData.name) {
      setError('Key and name are required')
      return
    }

    setSaving(true)
    setError(null)

    try {
      const planData = {
        key: formData.key,
        name: formData.name,
        price_cents: formData.price_cents,
        interval: formData.interval,
        features: {
          modules_allowed: formData.modules,
          limits: {},
        },
      }

      if (editingPlan && editingPlan.id) {
        const response = await planService.updatePlan(editingPlan.id, planData)
        if (!response.success) {
          setError(response.message || 'Failed to update plan')
          return
        }
      } else {
        const response = await planService.createPlan(planData)
        if (!response.success) {
          setError(response.message || 'Failed to create plan')
          return
        }
      }

      // Refresh plans
      const plansResponse = await planService.getPlans()
      if (plansResponse.success) {
        setPlans(plansResponse.data)
      }

      setEditingPlan(null)
      setFormData({
        key: '',
        name: '',
        price_cents: 0,
        interval: 'month',
        modules: [],
      })
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to save plan')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async (plan: Plan) => {
    if (!plan.id) return
    if (!confirm(`Are you sure you want to delete plan "${plan.name}"?`)) return

    try {
      const response = await planService.deletePlan(plan.id)
      if (!response.success) {
        setError(response.message || 'Failed to delete plan')
        return
      }

      // Refresh plans
      const plansResponse = await planService.getPlans()
      if (plansResponse.success) {
        setPlans(plansResponse.data)
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to delete plan')
    }
  }

  const toggleModule = (moduleKey: string) => {
    setFormData((prev) => ({
      ...prev,
      modules: prev.modules.includes(moduleKey)
        ? prev.modules.filter((k) => k !== moduleKey)
        : [...prev.modules, moduleKey],
    }))
  }

  if (loading) {
    return <div className="text-sm text-white/60">Loading plans...</div>
  }

  return (
    <div className="space-y-6">
      <div className="space-y-1 text-center">
        <h1 className="text-2xl font-semibold text-white">Manage Plans</h1>
        <p className="text-sm text-white/60">Create and manage subscription plans</p>
      </div>

      {error && (
        <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
          {error}
        </div>
      )}

      <div className="grid gap-6 lg:grid-cols-2">
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <h2 className="text-lg font-semibold text-white">Plans</h2>
            <button
              type="button"
              onClick={handleCreate}
              className="rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-400"
            >
              + New Plan
            </button>
          </div>

          <div className="space-y-3">
            {plans.map((plan) => (
              <div
                key={plan.key}
                className="rounded-lg border border-white/10 bg-[#0f151d] p-4 text-white"
              >
                <div className="flex items-start justify-between">
                  <div>
                    <h3 className="font-semibold">{plan.name}</h3>
                    <p className="text-xs text-white/60">Key: {plan.key}</p>
                    <p className="text-xs text-white/60">
                      ${(plan.price_cents / 100).toFixed(2)} / {plan.interval || 'one-time'}
                    </p>
                    <p className="mt-2 text-xs text-white/60">
                      Modules: {(plan.features.modules_allowed || plan.features.modules || []).length}
                    </p>
                  </div>
                  <div className="flex gap-2">
                    <button
                      type="button"
                      onClick={() => handleEdit(plan)}
                      className="rounded-md border border-white/10 bg-white/5 px-3 py-1 text-xs text-white/70 hover:bg-white/10"
                    >
                      Edit
                    </button>
                    <button
                      type="button"
                      onClick={() => handleDelete(plan)}
                      className="rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-1 text-xs text-rose-200 hover:bg-rose-500/20"
                    >
                      Delete
                    </button>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </div>

        <div className="rounded-lg border border-white/10 bg-[#0f151d] p-5 text-white">
          <h2 className="mb-4 text-lg font-semibold">
            {editingPlan ? 'Edit Plan' : 'Create Plan'}
          </h2>

          <div className="space-y-4">
            <div>
              <label className="mb-1 block text-xs text-white/60">Key</label>
              <input
                type="text"
                value={formData.key}
                onChange={(e) => setFormData({ ...formData, key: e.target.value.toLowerCase().replace(/[^a-z0-9]/g, '') })}
                disabled={!!editingPlan}
                className="w-full rounded-md border border-white/10 bg-white/5 px-3 py-2 text-sm text-white focus:border-sky-500 focus:outline-none disabled:opacity-50"
                placeholder="e.g., starter"
              />
            </div>

            <div>
              <label className="mb-1 block text-xs text-white/60">Name</label>
              <input
                type="text"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                className="w-full rounded-md border border-white/10 bg-white/5 px-3 py-2 text-sm text-white focus:border-sky-500 focus:outline-none"
                placeholder="e.g., Starter Plan"
              />
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="mb-1 block text-xs text-white/60">Price (cents)</label>
                <input
                  type="number"
                  value={formData.price_cents}
                  onChange={(e) => setFormData({ ...formData, price_cents: parseInt(e.target.value) || 0 })}
                  className="w-full rounded-md border border-white/10 bg-white/5 px-3 py-2 text-sm text-white focus:border-sky-500 focus:outline-none"
                  min="0"
                />
              </div>
              <div>
                <label className="mb-1 block text-xs text-white/60">Interval</label>
                <select
                  value={formData.interval || ''}
                  onChange={(e) => setFormData({ ...formData, interval: (e.target.value || null) as 'month' | 'year' | null })}
                  className="w-full rounded-md border border-white/10 bg-white/5 px-3 py-2 text-sm text-white focus:border-sky-500 focus:outline-none"
                >
                  <option value="">One-time</option>
                  <option value="month">Monthly</option>
                  <option value="year">Yearly</option>
                </select>
              </div>
            </div>

            <div>
              <label className="mb-2 block text-xs text-white/60">Modules</label>
              <div className="max-h-48 space-y-2 overflow-y-auto rounded-md border border-white/10 bg-white/5 p-3">
                {modules.map((module) => (
                  <label key={module.key} className="flex items-center gap-2 text-xs">
                    <input
                      type="checkbox"
                      checked={formData.modules.includes(module.key)}
                      onChange={() => toggleModule(module.key)}
                      className="rounded border-white/20"
                    />
                    <span>{module.name}</span>
                  </label>
                ))}
              </div>
            </div>

            <div className="flex gap-2">
              <button
                type="button"
                onClick={handleSave}
                disabled={saving || !formData.key || !formData.name}
                className="flex-1 rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-400 disabled:opacity-50"
              >
                {saving ? 'Saving...' : editingPlan ? 'Update' : 'Create'}
              </button>
              {editingPlan && (
                <button
                  type="button"
                  onClick={() => {
                    setEditingPlan(null)
                    setFormData({
                      key: '',
                      name: '',
                      price_cents: 0,
                      interval: 'month',
                      modules: [],
                    })
                  }}
                  className="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-white/70 transition hover:bg-white/10"
                >
                  Cancel
                </button>
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}

export default AdminPlans
