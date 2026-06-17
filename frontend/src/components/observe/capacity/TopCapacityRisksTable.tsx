import type { CapacityTopRisk } from '../../../types/observe'
import { EmptyState } from './EmptyState'

interface TopCapacityRisksTableProps {
  risks: CapacityTopRisk[]
  labels: {
    title: string
    host: string
    resource: string
    utilization: string
    trend: string
    runway: string
    riskLevel: string
    lastSample: string
    empty: string
    insufficient: string
    days: string
    trendLabels: Record<string, string>
    riskLabels: Record<string, string>
  }
}

const riskBadge: Record<string, string> = {
  critical: 'bg-rose-500/20 text-rose-200 border-rose-500/30',
  warning: 'bg-amber-500/20 text-amber-200 border-amber-500/30',
  healthy: 'bg-emerald-500/20 text-emerald-200 border-emerald-500/30',
  insufficient_data: 'bg-white/10 text-white/50 border-white/10',
}

export function TopCapacityRisksTable({ risks, labels }: TopCapacityRisksTableProps) {
  if (risks.length === 0) {
    return <EmptyState title={labels.empty} />
  }

  return (
    <div className="rounded-2xl border border-white/10 bg-[#0f151d] overflow-hidden">
      <div className="border-b border-white/10 px-4 py-3">
        <h3 className="text-sm font-semibold text-white">{labels.title}</h3>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[640px] text-left text-xs text-white">
          <thead>
            <tr className="border-b border-white/10 text-white/45">
              <th className="px-4 py-2 font-medium">{labels.host}</th>
              <th className="px-4 py-2 font-medium">{labels.resource}</th>
              <th className="px-4 py-2 font-medium">{labels.utilization}</th>
              <th className="px-4 py-2 font-medium">{labels.trend}</th>
              <th className="px-4 py-2 font-medium">{labels.runway}</th>
              <th className="px-4 py-2 font-medium">{labels.riskLevel}</th>
              <th className="px-4 py-2 font-medium">{labels.lastSample}</th>
            </tr>
          </thead>
          <tbody>
            {risks.map((risk) => (
              <tr key={`${risk.host}-${risk.resource}`} className="border-b border-white/5 hover:bg-white/5">
                <td className="px-4 py-2.5 font-medium">{risk.host}</td>
                <td className="px-4 py-2.5 uppercase">{risk.resource}</td>
                <td className="px-4 py-2.5 tabular-nums">{risk.utilization_pct}%</td>
                <td className="px-4 py-2.5">{labels.trendLabels[risk.trend] ?? risk.trend}</td>
                <td className="px-4 py-2.5 tabular-nums">
                  {risk.runway_days != null ? `${risk.runway_days} ${labels.days}` : labels.insufficient}
                </td>
                <td className="px-4 py-2.5">
                  <span
                    className={`inline-block rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase ${riskBadge[risk.risk_level] ?? riskBadge.insufficient_data}`}
                  >
                    {labels.riskLabels[risk.risk_level] ?? risk.risk_level}
                  </span>
                </td>
                <td className="px-4 py-2.5 text-white/60">
                  {risk.last_sample_at ? new Date(risk.last_sample_at).toLocaleString() : labels.insufficient}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
