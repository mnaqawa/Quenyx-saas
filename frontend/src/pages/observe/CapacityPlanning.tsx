import { useState } from 'react'
import { useCapacityMetrics } from '../../hooks/useObserveData'
import { PageHeader } from '../../components/observe/PageHeader'
import { Tabs } from '../../components/observe/Tabs'
import { StatusBadge } from '../../components/observe/StatusBadge'

export default function CapacityPlanning() {
  const { metrics, loading } = useCapacityMetrics()
  const [range, setRange] = useState('12 Months')
  const [activeTab, setActiveTab] = useState('forecast')

  if (loading) {
    return <div className="text-sm text-white/60">Loading...</div>
  }

  const tabs = [
    { id: 'forecast', label: 'Capacity Forecast' },
    { id: 'pools', label: 'Resource Pools' },
    { id: 'recommendations', label: 'Recommendations' },
    { id: 'growth', label: 'Growth Projections' },
  ]

  const getStatusIcon = (status: string) => {
    if (status === 'critical') return '⚠'
    if (status === 'warning') return '⚠'
    if (status === 'healthy') return '✓'
    return ''
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Capacity Planning"
        subtitle="Resource planning and forecasting for optimal infrastructure scaling"
        actions={
          <>
            <select
              value={range}
              onChange={(e) => setRange(e.target.value)}
              className="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white"
            >
              <option value="12 Months" className="bg-slate-900 text-white">12 Months</option>
              <option value="6 Months" className="bg-slate-900 text-white">6 Months</option>
              <option value="3 Months" className="bg-slate-900 text-white">3 Months</option>
            </select>
            <button title="Coming soon" disabled className="cursor-not-allowed rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white/40">
              Export Report
            </button>
            <button title="Coming soon" disabled className="cursor-not-allowed rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white/40">
              Configure
            </button>
          </>
        }
      />

      <div className="grid gap-4 md:grid-cols-4">
        {metrics.map((metric) => (
          <div key={metric.title} className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
            <div className="mb-2 flex items-start justify-between">
              <div className="flex-1">
                <p className="text-xs text-white/50">{metric.title}</p>
                <p className="mt-1 text-2xl font-semibold">{metric.value}</p>
                <div className="mt-2 flex items-center gap-1">
                  <StatusBadge status={metric.status} label={getStatusIcon(metric.status)} />
                  <span className="text-xs text-white/60">{metric.description}</span>
                </div>
              </div>
            </div>
          </div>
        ))}
      </div>

      <Tabs tabs={tabs} activeTab={activeTab} onTabChange={setActiveTab} />

      <div className="grid gap-4 md:grid-cols-2">
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <div className="mb-4">
            <h3 className="text-sm font-semibold">Historical Usage Trends</h3>
            <p className="text-xs text-white/60">Resource utilization over the past 6 months</p>
          </div>
          <div className="h-64 rounded-lg bg-white/5 p-4">
            <div className="flex h-full items-center justify-center text-sm text-white/40">
              Chart placeholder - Historical trends
            </div>
          </div>
        </div>

        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <div className="mb-4">
            <h3 className="text-sm font-semibold">Capacity Forecast</h3>
            <p className="text-xs text-white/60">Projected resource usage for next 6 months</p>
          </div>
          <div className="h-64 rounded-lg bg-white/5 p-4">
            <div className="flex h-full items-center justify-center text-sm text-white/40">
              Chart placeholder - Capacity forecast
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
