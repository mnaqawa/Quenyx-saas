import { useState } from 'react'
import { useInstances, useInstanceSummary } from '../../hooks/useObserveData'
import { StatCard } from '../../components/observe/StatCard'
import { PageHeader } from '../../components/observe/PageHeader'
import { Tabs } from '../../components/observe/Tabs'
import { StatusBadge } from '../../components/observe/StatusBadge'
import { ProgressBar } from '../../components/observe/ProgressBar'

export default function InstanceManagement() {
  const { instances, loading: instancesLoading } = useInstances()
  const { summary, loading: summaryLoading } = useInstanceSummary()
  const [statusFilter, setStatusFilter] = useState('All Status')
  const [activeTab, setActiveTab] = useState('list')

  if (instancesLoading || summaryLoading) {
    return <div className="text-sm text-white/60">Loading...</div>
  }

  if (!summary) {
    return <div className="text-sm text-white/60">No data available</div>
  }

  const tabs = [
    { id: 'list', label: 'Instance List' },
    { id: 'templates', label: 'Templates' },
    { id: 'operations', label: 'Operations Log' },
    { id: 'performance', label: 'Performance' },
  ]

  return (
    <div className="space-y-6">
      <PageHeader
        title="Instance Management"
        subtitle="Manage server instances, control operations, and monitor performance"
        actions={
          <>
            <button title="Coming soon" disabled className="cursor-not-allowed rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white/40">
              Settings
            </button>
            <button title="Coming soon" disabled className="cursor-not-allowed rounded-lg bg-sky-500/50 px-4 py-1.5 text-xs font-semibold text-white/70">
              + Create Instance
            </button>
          </>
        }
      />

      <div className="grid gap-4 md:grid-cols-4">
        <StatCard
          title="Total Instances"
          value={summary.total.toString()}
          detail="Across all environments"
        />
        <StatCard
          title="Running"
          value={summary.running.toString()}
          detail={`${Math.round((summary.running / summary.total) * 100)}% of total`}
        />
        <StatCard
          title="Warning"
          value={summary.warning.toString()}
          detail="Require attention"
        />
        <StatCard
          title="Avg CPU Usage"
          value={`${summary.avgCpuUsage}%`}
          detail="Across all running instances"
        />
      </div>

      <Tabs tabs={tabs} activeTab={activeTab} onTabChange={setActiveTab} />

      {activeTab === 'list' && (
        <>
          <div className="flex items-center gap-2">
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white"
            >
              <option value="All Status" className="bg-slate-900 text-white">All Status</option>
              <option value="running" className="bg-slate-900 text-white">Running</option>
              <option value="stopped" className="bg-slate-900 text-white">Stopped</option>
              <option value="warning" className="bg-slate-900 text-white">Warning</option>
            </select>
          </div>

          <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
            <div className="mb-4">
              <h3 className="text-sm font-semibold">Server Instances</h3>
              <p className="text-xs text-white/60">Manage and monitor your server instances</p>
            </div>
            <div className="space-y-3">
              {instances.map((instance) => (
                <div
                  key={instance.id}
                  className="flex items-center gap-4 rounded-lg border border-white/5 bg-white/5 p-4"
                >
                  <div className="flex-1">
                    <div className="mb-2 flex items-center gap-3">
                      <h4 className="text-sm font-semibold">{instance.name}</h4>
                      <span className="text-xs text-white/60">{instance.type}</span>
                      <span className="text-xs text-white/40">{instance.ip}</span>
                      <span className="text-xs text-white/40">{instance.os}</span>
                    </div>
                    <div className="mb-2 flex items-center gap-4 text-xs text-white/60">
                      <span>{instance.specs.cores} cores</span>
                      <span>{instance.specs.ram} RAM</span>
                      <span>{instance.specs.disk}</span>
                    </div>
                    <div className="mb-2 grid grid-cols-3 gap-4">
                      <ProgressBar value={instance.usage.cpu} label="CPU" />
                      <ProgressBar value={instance.usage.memory} label="Memory" />
                      <ProgressBar value={instance.usage.disk} label="Disk" />
                    </div>
                    <div className="flex items-center gap-4 text-xs text-white/40">
                      <span>Uptime: {instance.uptime}</span>
                      <span>Datacenter: {instance.datacenter}</span>
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    <StatusBadge status={instance.status} label={instance.status} />
                    <div className="flex gap-1">
                      <button title="Coming soon" disabled className="cursor-not-allowed rounded border border-white/10 bg-white/5 p-1.5 text-white/40">
                        ▶
                      </button>
                      <button title="Coming soon" disabled className="cursor-not-allowed rounded border border-white/10 bg-white/5 p-1.5 text-white/40">
                        ⏸
                      </button>
                      <button title="Coming soon" disabled className="cursor-not-allowed rounded border border-white/10 bg-white/5 p-1.5 text-white/40">
                        ↻
                      </button>
                      <button title="Coming soon" disabled className="cursor-not-allowed rounded border border-white/10 bg-white/5 p-1.5 text-white/40">
                        ✏️
                      </button>
                      <button title="Coming soon" disabled className="cursor-not-allowed rounded border border-white/10 bg-white/5 p-1.5 text-white/40">
                        🗑️
                      </button>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </>
      )}

      {activeTab !== 'list' && (
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-10 text-center text-white">
          <p className="text-sm text-white/60">{tabs.find((t) => t.id === activeTab)?.label} view coming soon</p>
        </div>
      )}
    </div>
  )
}
