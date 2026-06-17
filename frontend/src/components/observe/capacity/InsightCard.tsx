import type { CapacityInsight } from '../../../types/observe'

interface InsightCardProps {
  insight: CapacityInsight
  priorityLabel: string
  typeLabel?: string
  labels: {
    severity: string
    evidence: string
    recommendation: string
    operationalImpact: string
    costImpact: string
    costUnavailable: string
    created: string
  }
}

const priorityClass = {
  high: 'border-rose-500/30 bg-rose-500/10 text-rose-200',
  medium: 'border-amber-500/30 bg-amber-500/10 text-amber-200',
  low: 'border-sky-500/30 bg-sky-500/10 text-sky-200',
}

export function InsightCard({ insight, priorityLabel, typeLabel, labels }: InsightCardProps) {
  return (
    <div className="rounded-xl border border-white/10 bg-white/5 p-4 text-white">
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <div>
          <span className="text-sm font-semibold">{insight.title ?? insight.affected_resource}</span>
          {typeLabel ? (
            <span className="ms-2 text-[10px] uppercase text-white/40">{typeLabel}</span>
          ) : null}
        </div>
        <span
          className={`rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase ${priorityClass[insight.priority]}`}
        >
          {priorityLabel}
        </span>
      </div>
      <dl className="space-y-2 text-xs">
        <div>
          <dt className="text-white/45">{labels.evidence}</dt>
          <dd className="text-white/80">{insight.evidence ?? insight.issue}</dd>
        </div>
        <div>
          <dt className="text-white/45">{labels.recommendation}</dt>
          <dd className="text-white/80">{insight.recommended_action ?? insight.recommendation}</dd>
        </div>
        <div>
          <dt className="text-white/45">{labels.operationalImpact}</dt>
          <dd className="text-white/80">{insight.operational_impact ?? insight.expected_impact}</dd>
        </div>
        <div>
          <dt className="text-white/45">{labels.costImpact}</dt>
          <dd className="text-white/70">
            {insight.cost_impact_message ?? labels.costUnavailable}
          </dd>
        </div>
        <div>
          <dt className="text-white/45">{labels.created}</dt>
          <dd className="text-white/60">{new Date(insight.created_at).toLocaleString()}</dd>
        </div>
      </dl>
    </div>
  )
}
