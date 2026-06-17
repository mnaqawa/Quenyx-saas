import type { CapacityHealth, CapacityHealthStatus } from '../../../types/observe'

interface CapacityHealthPanelProps {
  health: CapacityHealth | null | undefined
  labels: {
    title: string
    healthStatus: string
    riskScore: string
    primaryRisk: string
    shortestRunway: string
    recommendedAction: string
    dataConfidence: string
    insufficient: string
    days: string
    status: Record<CapacityHealthStatus, string>
    confidence: Record<string, string>
  }
}

const healthClass: Record<CapacityHealthStatus, string> = {
  healthy: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-200',
  watch: 'border-amber-500/30 bg-amber-500/10 text-amber-200',
  risk: 'border-orange-500/30 bg-orange-500/10 text-orange-200',
  critical: 'border-rose-500/30 bg-rose-500/10 text-rose-200',
  no_data: 'border-white/10 bg-white/5 text-white/50',
}

export function CapacityHealthPanel({ health, labels }: CapacityHealthPanelProps) {
  const status = health?.health_status ?? 'no_data'

  return (
    <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
      <h3 className="mb-4 text-sm font-semibold">{labels.title}</h3>
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <span className="text-xs text-white/50">{labels.healthStatus}</span>
        <span className={`rounded-full border px-3 py-1 text-xs font-semibold uppercase ${healthClass[status]}`}>
          {labels.status[status]}
        </span>
      </div>
      <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div>
          <dt className="text-xs text-white/45">{labels.riskScore}</dt>
          <dd className="mt-1 text-lg font-semibold tabular-nums">
            {health?.risk_score != null ? `${Math.round(health.risk_score)}/100` : labels.insufficient}
          </dd>
        </div>
        <div>
          <dt className="text-xs text-white/45">{labels.primaryRisk}</dt>
          <dd className="mt-1 text-sm font-medium uppercase">
            {health?.primary_risk ?? labels.insufficient}
          </dd>
        </div>
        <div>
          <dt className="text-xs text-white/45">{labels.shortestRunway}</dt>
          <dd className="mt-1 text-sm font-medium tabular-nums">
            {health?.shortest_runway_days != null
              ? `${health.shortest_runway_days} ${labels.days}`
              : labels.insufficient}
          </dd>
        </div>
        <div className="sm:col-span-2">
          <dt className="text-xs text-white/45">{labels.recommendedAction}</dt>
          <dd className="mt-1 text-sm text-white/80">
            {health?.recommended_action ?? labels.insufficient}
          </dd>
        </div>
        <div>
          <dt className="text-xs text-white/45">{labels.dataConfidence}</dt>
          <dd className="mt-1 text-sm font-medium">
            {labels.confidence[health?.data_confidence ?? 'no_data']}
          </dd>
        </div>
      </dl>
    </div>
  )
}
