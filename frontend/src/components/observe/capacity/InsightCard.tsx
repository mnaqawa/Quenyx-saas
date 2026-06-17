import type { CapacityInsight } from '../../../types/observe'

interface InsightCardProps {
  insight: CapacityInsight
  priorityLabel: string
  labels: {
    issue: string
    recommendation: string
    impact: string
    saving: string
    created: string
  }
}

const priorityClass = {
  high: 'border-rose-500/30 bg-rose-500/10 text-rose-200',
  medium: 'border-amber-500/30 bg-amber-500/10 text-amber-200',
  low: 'border-sky-500/30 bg-sky-500/10 text-sky-200',
}

export function InsightCard({ insight, priorityLabel, labels }: InsightCardProps) {
  return (
    <div className="rounded-xl border border-white/10 bg-white/5 p-4 text-white">
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <span className="text-sm font-semibold">{insight.affected_resource}</span>
        <span
          className={`rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase ${priorityClass[insight.priority]}`}
        >
          {priorityLabel}
        </span>
      </div>
      <dl className="space-y-2 text-xs">
        <div>
          <dt className="text-white/45">{labels.issue}</dt>
          <dd className="text-white/80">{insight.issue}</dd>
        </div>
        <div>
          <dt className="text-white/45">{labels.recommendation}</dt>
          <dd className="text-white/80">{insight.recommendation}</dd>
        </div>
        <div>
          <dt className="text-white/45">{labels.impact}</dt>
          <dd className="text-white/80">{insight.expected_impact}</dd>
        </div>
        {insight.estimated_saving != null ? (
          <div>
            <dt className="text-white/45">{labels.saving}</dt>
            <dd className="text-white/80">{insight.estimated_saving}</dd>
          </div>
        ) : null}
        <div>
          <dt className="text-white/45">{labels.created}</dt>
          <dd className="text-white/60">{new Date(insight.created_at).toLocaleString()}</dd>
        </div>
      </dl>
    </div>
  )
}
