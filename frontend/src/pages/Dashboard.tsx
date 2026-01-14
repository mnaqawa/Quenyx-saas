import { useEffect, useState } from 'react'
import { dashboardService, DashboardData } from '../services/dashboardService'

function Dashboard() {
  const [data, setData] = useState<DashboardData | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const fetchDashboard = async () => {
      try {
        const dashboardData = await dashboardService.getDashboard()
        setData(dashboardData)
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load dashboard')
      } finally {
        setLoading(false)
      }
    }

    fetchDashboard()
  }, [])

  if (loading) {
    return <div className="text-sm text-slate-600">Loading...</div>
  }

  if (error) {
    return (
      <div className="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
        Error: {error}
      </div>
    )
  }

  if (!data) {
    return <div className="text-sm text-slate-600">No data available</div>
  }

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight text-slate-900">Dashboard</h1>
        <p className="text-sm text-slate-600">Platform overview (placeholder)</p>
      </div>

      {/* Platform Health Section */}
      <section className="rounded-lg border border-slate-200 bg-white p-4">
        <h2 className="text-base font-semibold text-slate-900">Platform Health</h2>
        <p className="mt-2 text-sm text-slate-700">
          Status: <span className="font-medium text-slate-900">{data.platform_health}</span>
        </p>
      </section>

      {/* Modules Overview Section */}
      <section className="rounded-lg border border-slate-200 bg-white p-4">
        <h2 className="text-base font-semibold text-slate-900">Modules Overview</h2>
        <div className="mt-3 space-y-3">
          {data.modules.map((module) => (
            <div key={module.id} className="rounded-md border border-slate-200 bg-slate-50 p-4">
              <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                  <h3 className="truncate text-sm font-semibold text-slate-900">{module.name}</h3>
                  <p className="mt-1 text-sm text-slate-600">{module.description}</p>
                </div>
                <div className="shrink-0 text-right text-xs text-slate-600">
                  <div>
                    Status: <span className="font-medium text-slate-900">{module.status}</span>
                  </div>
                  <div className="mt-1">
                    Subscription: <span className="font-medium text-slate-900">{module.subscription_state}</span>
                  </div>
                </div>
              </div>
            </div>
          ))}
        </div>
      </section>
    </div>
  )
}

export default Dashboard
