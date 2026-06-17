import type { CapacityHostScenarioImpact } from '../../../types/observe'
import { EmptyState } from './EmptyState'

interface ScenarioHostImpactsTableProps {
  impacts: CapacityHostScenarioImpact[]
  labels: {
    title: string
    host: string
    resource: string
    currentUtil: string
    currentRunway: string
    projectedUtil: string
    projectedRunway: string
    riskBefore: string
    riskAfter: string
    impact: string
    empty: string
    insufficient: string
    days: string
    statusCalculated: string
    statusInsufficient: string
    riskLabels: Record<string, string>
  }
}

export function ScenarioHostImpactsTable({ impacts, labels }: ScenarioHostImpactsTableProps) {
  if (impacts.length === 0) {
    return <EmptyState title={labels.empty} />
  }

  return (
    <div className="rounded-2xl border border-white/10 bg-[#0f151d] overflow-hidden">
      <div className="border-b border-white/10 px-4 py-3">
        <h3 className="text-sm font-semibold text-white">{labels.title}</h3>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[900px] text-left text-xs text-white">
          <thead>
            <tr className="border-b border-white/10 text-white/45">
              <th className="px-4 py-2 font-medium">{labels.host}</th>
              <th className="px-4 py-2 font-medium">{labels.resource}</th>
              <th className="px-4 py-2 font-medium">{labels.currentUtil}</th>
              <th className="px-4 py-2 font-medium">{labels.currentRunway}</th>
              <th className="px-4 py-2 font-medium">{labels.projectedUtil}</th>
              <th className="px-4 py-2 font-medium">{labels.projectedRunway}</th>
              <th className="px-4 py-2 font-medium">{labels.riskBefore}</th>
              <th className="px-4 py-2 font-medium">{labels.riskAfter}</th>
            </tr>
          </thead>
          <tbody>
            {impacts.map((row) => (
              <tr key={`${row.host_name}-${row.resource}`} className="border-b border-white/5 align-top hover:bg-white/5">
                <td className="px-4 py-2.5 font-medium">{row.host_name}</td>
                <td className="px-4 py-2.5 uppercase">{row.resource}</td>
                <td className="px-4 py-2.5 tabular-nums">
                  {row.current_utilization != null ? `${row.current_utilization}%` : labels.insufficient}
                </td>
                <td className="px-4 py-2.5 tabular-nums">
                  {row.current_runway_days != null ? `${row.current_runway_days} ${labels.days}` : labels.insufficient}
                </td>
                <td className="px-4 py-2.5 tabular-nums">
                  {row.projected_utilization != null ? `${row.projected_utilization}%` : labels.insufficient}
                </td>
                <td className="px-4 py-2.5 tabular-nums">
                  {row.projected_runway_days != null ? `${row.projected_runway_days} ${labels.days}` : labels.insufficient}
                </td>
                <td className="px-4 py-2.5">{labels.riskLabels[row.risk_before] ?? row.risk_before}</td>
                <td className="px-4 py-2.5">{labels.riskLabels[row.risk_after] ?? row.risk_after}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="space-y-2 border-t border-white/10 px-4 py-3 text-xs text-white/65">
        {impacts.map((row) =>
          row.impact_summary ? (
            <p key={`${row.host_name}-${row.resource}-summary`}>
              <span className="font-medium text-white/80">{row.host_name} / {row.resource}:</span>{' '}
              {row.impact_summary}
            </p>
          ) : null,
        )}
      </div>
    </div>
  )
}
