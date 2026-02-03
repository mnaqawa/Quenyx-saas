import { useDataSources, useDataSourceSummary } from '../../hooks/useObserveData'
import { StatCard } from '../../components/observe/StatCard'
import { PageHeader } from '../../components/observe/PageHeader'
import { StatusBadge } from '../../components/observe/StatusBadge'

export default function DataSources() {
  const { sources, loading: sourcesLoading } = useDataSources()
  const { summary, loading: summaryLoading } = useDataSourceSummary()

  if (sourcesLoading || summaryLoading) {
    return <div className="text-sm text-white/60">Loading...</div>
  }

  if (!summary) {
    return <div className="text-sm text-white/60">No data available</div>
  }

  const formatRecordCount = (count: number) => {
    if (count >= 1000000) return `${(count / 1000000).toFixed(1)}M`
    if (count >= 1000) return `${(count / 1000).toFixed(0)}K`
    return count.toString()
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Data Sources"
        subtitle="Manage and monitor your data connections"
        actions={
          <button
            title="Coming soon"
            disabled
            className="cursor-not-allowed rounded-lg bg-sky-500/50 px-4 py-1.5 text-xs font-semibold text-white/70"
          >
            + Add Data Source
          </button>
        }
      />

      <div className="grid gap-4 md:grid-cols-4">
        <StatCard
          title="Connected Sources"
          value={summary.connected.toString()}
          detail="+2 this month"
        />
        <StatCard
          title="Total Records"
          value={summary.totalRecords}
          detail="Across all sources"
        />
        <StatCard
          title="Sync Status"
          value={`${summary.syncStatus}%`}
          detail="Healthy connections"
        />
        <StatCard
          title="Last Update"
          value={summary.lastUpdate}
          detail="Minutes ago"
        />
      </div>

      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
        <h3 className="mb-4 text-sm font-semibold">Data Source Connections</h3>
        <div className="space-y-3">
          {sources.map((source) => (
            <div
              key={source.id}
              className="flex items-center gap-4 rounded-lg border border-white/5 bg-white/5 p-4"
            >
              <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-white/5 text-white">
                🗄️
              </div>
              <div className="flex-1">
                <h4 className="text-sm font-semibold">{source.name}</h4>
                <p className="text-xs text-white/60">
                  {source.type} • {formatRecordCount(source.recordCount)} records • Last sync: {source.lastSync}
                </p>
              </div>
              <div className="flex items-center gap-2">
                <StatusBadge status={source.status} label={source.status} />
                <button title="Coming soon" disabled className="cursor-not-allowed rounded-lg border border-white/10 bg-white/5 p-2 text-white/40">
                  ⚙️
                </button>
                <button title="Coming soon" disabled className="cursor-not-allowed rounded-lg border border-white/10 bg-white/5 p-2 text-white/40">
                  ↻
                </button>
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}
