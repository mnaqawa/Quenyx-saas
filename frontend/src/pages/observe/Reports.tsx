import { useReports, useReportSummary } from '../../hooks/useObserveData'
import { StatCard } from '../../components/observe/StatCard'
import { PageHeader } from '../../components/observe/PageHeader'
import { StatusBadge } from '../../components/observe/StatusBadge'

export default function Reports() {
  const { reports, loading: reportsLoading } = useReports()
  const { summary, loading: summaryLoading } = useReportSummary()

  if (reportsLoading || summaryLoading) {
    return <div className="text-sm text-white/60">Loading...</div>
  }

  if (!summary) {
    return <div className="text-sm text-white/60">No data available</div>
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Reports"
        subtitle="Generate and manage analytical reports"
        actions={
          <button title="Coming soon" disabled className="cursor-not-allowed rounded-lg bg-sky-500/50 px-4 py-1.5 text-xs font-semibold text-white/70">
            Generate Report
          </button>
        }
      />

      <div className="grid gap-4 md:grid-cols-4">
        <StatCard
          title="Total Reports"
          value={summary.total.toString()}
          detail="+4 this week"
        />
        <StatCard
          title="Downloads"
          value={summary.downloads.toLocaleString()}
          detail="+12% from last month"
        />
        <StatCard
          title="Scheduled"
          value={summary.scheduled.toString()}
          detail="Active schedules"
        />
        <StatCard
          title="Avg. Size"
          value={summary.avgSize}
          detail="Per report"
        />
      </div>

      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
        <h3 className="mb-4 text-sm font-semibold">Recent Reports</h3>
        <div className="space-y-3">
          {reports.map((report) => (
            <div
              key={report.id}
              className="flex items-center gap-4 rounded-lg border border-white/5 bg-white/5 p-4"
            >
              <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-sky-500/20 text-sky-200">
                📄
              </div>
              <div className="flex-1">
                <h4 className="text-sm font-semibold">{report.name}</h4>
                <p className="text-xs text-white/60">
                  {report.category} • {report.date} • {report.size}
                </p>
              </div>
              <div className="flex items-center gap-2">
                <StatusBadge status={report.status === 'failed' ? 'error' : report.status} label={report.status} />
                {report.status === 'completed' && (
                  <button title="Coming soon" disabled className="cursor-not-allowed rounded-lg border border-white/10 bg-white/5 p-2 text-white/40">
                    ⬇
                  </button>
                )}
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}
