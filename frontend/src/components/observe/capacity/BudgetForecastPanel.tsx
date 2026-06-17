import type { CapacityBudget, CapacityForecastRequirements } from '../../../types/observe'
import { EmptyState } from './EmptyState'

interface BudgetForecastPanelProps {
  budget: CapacityBudget | null | undefined
  labels: {
    title: string
    forecastCpu: string
    forecastMemory: string
    forecastStorage: string
    timeline: string
    costStatus: string
    costUnavailable: string
    connectBilling: string
    insufficient: string
    days: string
    pctGrowth: string
    empty: string
    emptyDesc: string
  }
  onConnectBilling?: () => void
}

function formatRequirement(value: number | null | undefined, pctLabel: string): string {
  if (value == null) return '—'
  return `+${value}${pctLabel}`
}

export function BudgetForecastPanel({ budget, labels, onConnectBilling }: BudgetForecastPanelProps) {
  const req: CapacityForecastRequirements = budget?.forecasted_requirements ?? {
    cpu: null,
    memory: null,
    storage: null,
    timeline_days: null,
  }

  const hasForecast =
    req.cpu != null || req.memory != null || req.storage != null || req.timeline_days != null

  if (!hasForecast && !budget) {
    return <EmptyState title={labels.empty} description={labels.emptyDesc} />
  }

  return (
    <div className="space-y-4">
      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
        <h3 className="mb-4 text-sm font-semibold">{labels.title}</h3>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <ForecastCard label={labels.forecastCpu} value={formatRequirement(req.cpu, labels.pctGrowth)} />
          <ForecastCard label={labels.forecastMemory} value={formatRequirement(req.memory, labels.pctGrowth)} />
          <ForecastCard label={labels.forecastStorage} value={formatRequirement(req.storage, labels.pctGrowth)} />
          <ForecastCard
            label={labels.timeline}
            value={req.timeline_days != null ? `${req.timeline_days} ${labels.days}` : labels.insufficient}
          />
        </div>
      </div>

      <div className="rounded-xl border border-amber-500/20 bg-amber-500/5 p-4 text-white">
        <p className="text-xs text-white/50">{labels.costStatus}</p>
        <p className="mt-1 text-sm text-amber-100">{labels.costUnavailable}</p>
        {onConnectBilling ? (
          <button
            type="button"
            onClick={onConnectBilling}
            className="mt-3 rounded-lg border border-amber-500/40 bg-amber-500/20 px-3 py-1.5 text-xs font-semibold text-amber-100 hover:bg-amber-500/30"
          >
            {labels.connectBilling}
          </button>
        ) : null}
      </div>
    </div>
  )
}

function ForecastCard({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg border border-white/10 bg-white/5 p-3">
      <p className="text-xs text-white/45">{label}</p>
      <p className="mt-1 text-lg font-semibold tabular-nums">{value}</p>
    </div>
  )
}
