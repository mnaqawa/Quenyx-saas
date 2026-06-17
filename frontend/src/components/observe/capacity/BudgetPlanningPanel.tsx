import type { CapacityBudgetPlanning } from '../../../types/observe'
import { EmptyState } from './EmptyState'

interface BudgetPlanningPanelProps {
  budget: CapacityBudgetPlanning
  labels: {
    currentCost: string
    forecastedCost: string
    variance: string
    savings: string
    providers: string
    empty: string
    noData: string
    insufficient: string
  }
}

export function BudgetPlanningPanel({ budget, labels }: BudgetPlanningPanelProps) {
  const hasData =
    budget.current_monthly_cost != null ||
    budget.forecasted_cost.length > 0 ||
    budget.budget_variance != null ||
    budget.saving_opportunities.length > 0 ||
    budget.provider_breakdown.length > 0

  if (!hasData) {
    return <EmptyState title={labels.empty} description={labels.noData} />
  }

  return (
    <div className="grid gap-4 md:grid-cols-2">
      <div className="rounded-xl border border-white/10 bg-[#0f151d] p-4 text-white">
        <p className="text-xs text-white/50">{labels.currentCost}</p>
        <p className="mt-1 text-xl font-semibold tabular-nums">
          {budget.current_monthly_cost != null ? budget.current_monthly_cost : labels.insufficient}
        </p>
      </div>
      <div className="rounded-xl border border-white/10 bg-[#0f151d] p-4 text-white">
        <p className="text-xs text-white/50">{labels.variance}</p>
        <p className="mt-1 text-xl font-semibold tabular-nums">
          {budget.budget_variance != null ? budget.budget_variance : labels.insufficient}
        </p>
      </div>
    </div>
  )
}
