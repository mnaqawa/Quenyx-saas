import type { CapacityScenario } from '../../../types/observe'

interface ScenarioCardProps {
  scenario: CapacityScenario
  nameLabel: string
  limitingLabel: string
  runwayLabel: string
  monthsLabel: string
  insufficientLabel: string
}

export function ScenarioCard({
  scenario,
  nameLabel,
  limitingLabel,
  runwayLabel,
  monthsLabel,
  insufficientLabel,
}: ScenarioCardProps) {
  return (
    <div className="rounded-xl border border-white/10 bg-[#0f151d] p-4 text-white">
      <h4 className="text-sm font-semibold">{nameLabel}</h4>
      <p className="mt-1 text-xs text-white/55">{scenario.description}</p>
      <div className="mt-4 grid gap-2 text-xs">
        <div className="flex justify-between gap-3">
          <span className="text-white/45">{limitingLabel}</span>
          <span className="font-medium uppercase">{scenario.limiting_resource}</span>
        </div>
        <div className="flex justify-between gap-3">
          <span className="text-white/45">{runwayLabel}</span>
          <span className="font-medium tabular-nums">
            {scenario.runway_months != null ? `${scenario.runway_months} ${monthsLabel}` : insufficientLabel}
          </span>
        </div>
      </div>
    </div>
  )
}
