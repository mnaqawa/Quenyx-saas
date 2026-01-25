import { useState } from 'react'
import { usePerformanceMetrics } from '../../hooks/useObserveData'
import { StatCard } from '../../components/observe/StatCard'
import { PageHeader } from '../../components/observe/PageHeader'
import { Tabs } from '../../components/observe/Tabs'

export default function PerformanceAnalytics() {
  const { metrics, loading } = usePerformanceMetrics()
  const [timeRange, setTimeRange] = useState('Last 24 Hours')
  const [activeTab, setActiveTab] = useState('overview')

  if (loading) {
    return <div className="text-sm text-white/60">Loading...</div>
  }

  const tabs = [
    { id: 'overview', label: 'Performance Overview' },
    { id: 'cpu', label: 'CPU Analysis' },
    { id: 'memory', label: 'Memory Analysis' },
    { id: 'disk', label: 'Disk Analysis' },
    { id: 'network', label: 'Network Analysis' },
  ]

  return (
    <div className="space-y-6">
      <PageHeader
        title="Performance Analytics"
        subtitle="Deep analysis of CPU, RAM, Disk, and Network performance"
        actions={
          <>
            <select
              value={timeRange}
              onChange={(e) => setTimeRange(e.target.value)}
              className="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white"
            >
              <option value="Last 24 Hours" className="bg-slate-900 text-white">Last 24 Hours</option>
              <option value="Last 7 Days" className="bg-slate-900 text-white">Last 7 Days</option>
              <option value="Last 30 Days" className="bg-slate-900 text-white">Last 30 Days</option>
            </select>
            <button className="rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white/70">
              Export
            </button>
            <button className="rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white/70">
              Configure
            </button>
          </>
        }
      />

      <div className="grid gap-4 md:grid-cols-4">
        {metrics.map((metric) => (
          <StatCard
            key={metric.title}
            title={metric.title}
            value={metric.value}
            detail={metric.detail}
            trend={metric.trend}
            percentage={metric.percentage}
          />
        ))}
      </div>

      <Tabs tabs={tabs} activeTab={activeTab} onTabChange={setActiveTab} />

      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
        <div className="mb-4">
          <h3 className="text-sm font-semibold">System Performance Trends</h3>
          <p className="text-xs text-white/60">Resource utilization over time</p>
        </div>
        <div className="h-96 rounded-lg bg-white/5 p-4">
          <div className="flex h-full items-center justify-center text-sm text-white/40">
            Chart placeholder - Performance trends over {timeRange}
          </div>
        </div>
      </div>
    </div>
  )
}
