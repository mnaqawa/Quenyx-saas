import { useEffect, useState } from 'react'
import type { CapacityScenario, CapacityScenarioParams, CapacityScenarioTemplate } from '../../../types/observe'

interface ScenarioTemplateCardProps {
  template: CapacityScenarioTemplate
  calculated?: CapacityScenario
  availableHosts: string[]
  nameLabel: string
  labels: {
    growth: string
    horizon: string
    targetResource: string
    hosts: string
    hostsAll: string
    calculate: string
    currentRunway: string
    projectedRunway: string
    riskChange: string
    impactSummary: string
    confidence: string
    insufficient: string
    cannotCalculate: string
    days: string
    months: string
    resources: Record<string, string>
    riskChangeLabels: Record<string, string>
    confidenceLevels: Record<string, string>
  }
  onCalculate: (params: CapacityScenarioParams & { scenario_template: string }) => void
}

export function ScenarioTemplateCard({
  template,
  calculated,
  availableHosts,
  nameLabel,
  labels,
  onCalculate,
}: ScenarioTemplateCardProps) {
  const [growth, setGrowth] = useState(template.default_growth_pct)
  const [horizon, setHorizon] = useState(template.default_horizon_days)
  const [resource, setResource] = useState(template.default_resource)
  const [selectedHosts, setSelectedHosts] = useState<string[]>(availableHosts)
  const [allHosts, setAllHosts] = useState(true)

  useEffect(() => {
    setSelectedHosts(availableHosts)
    setAllHosts(true)
  }, [availableHosts])

  const result = calculated?.template === template.id || calculated?.id === template.id ? calculated : undefined

  const toggleHost = (host: string) => {
    setAllHosts(false)
    setSelectedHosts((prev) =>
      prev.includes(host) ? prev.filter((h) => h !== host) : [...prev, host],
    )
  }

  const handleAllHosts = (checked: boolean) => {
    setAllHosts(checked)
    if (checked) {
      setSelectedHosts(availableHosts)
    }
  }

  const submit = () => {
    const hostsParam =
      allHosts || selectedHosts.length === 0 || selectedHosts.length === availableHosts.length
        ? undefined
        : selectedHosts.join(',')
    onCalculate({
      scenario_template: template.id,
      growth_pct: growth,
      horizon_days: horizon,
      target_resource: resource,
      hosts: hostsParam,
    })
  }

  return (
    <div className="rounded-xl border border-white/10 bg-[#0f151d] p-4 text-white">
      <h4 className="text-sm font-semibold">{nameLabel}</h4>
      <div className="mt-3 grid gap-3 text-xs">
        <label className="flex flex-col gap-1">
          <span className="text-white/45">{labels.growth}</span>
          <input
            type="number"
            min={1}
            max={500}
            value={growth}
            onChange={(e) => setGrowth(Number(e.target.value))}
            className="rounded-lg border border-white/10 bg-white/5 px-2 py-1.5 outline-none"
          />
        </label>
        <label className="flex flex-col gap-1">
          <span className="text-white/45">{labels.horizon}</span>
          <input
            type="number"
            min={7}
            max={730}
            value={horizon}
            onChange={(e) => setHorizon(Number(e.target.value))}
            className="rounded-lg border border-white/10 bg-white/5 px-2 py-1.5 outline-none"
          />
        </label>
        <label className="flex flex-col gap-1">
          <span className="text-white/45">{labels.targetResource}</span>
          <select
            value={resource}
            onChange={(e) => setResource(e.target.value)}
            className="rounded-lg border border-white/10 bg-white/5 px-2 py-1.5 outline-none"
          >
            {['cpu', 'memory', 'storage', 'all'].map((r) => (
              <option key={r} value={r} className="bg-[#0f151d]">
                {labels.resources[r] ?? r}
              </option>
            ))}
          </select>
        </label>
        {availableHosts.length > 0 ? (
          <div className="flex flex-col gap-1">
            <span className="text-white/45">{labels.hosts}</span>
            <label className="flex items-center gap-2">
              <input type="checkbox" checked={allHosts} onChange={(e) => handleAllHosts(e.target.checked)} />
              <span>{labels.hostsAll}</span>
            </label>
            <div className="max-h-28 space-y-1 overflow-y-auto rounded-lg border border-white/10 bg-white/5 p-2">
              {availableHosts.map((host) => (
                <label key={host} className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    checked={allHosts || selectedHosts.includes(host)}
                    disabled={allHosts}
                    onChange={() => toggleHost(host)}
                  />
                  <span className="truncate">{host}</span>
                </label>
              ))}
            </div>
          </div>
        ) : null}
        <button
          type="button"
          onClick={submit}
          className="rounded-lg bg-sky-500/80 px-3 py-1.5 text-xs font-semibold hover:bg-sky-400"
        >
          {labels.calculate}
        </button>
      </div>

      {result ? (
        <div className="mt-4 space-y-2 border-t border-white/10 pt-3 text-xs">
          {result.calculable === false ? (
            <p className="text-white/55">{labels.cannotCalculate}</p>
          ) : (
            <>
              <Row
                label={labels.currentRunway}
                value={
                  result.current_runway_days != null
                    ? `${result.current_runway_days} ${labels.days}`
                    : result.current_runway_months != null
                      ? `${result.current_runway_months} ${labels.months}`
                      : labels.insufficient
                }
              />
              <Row
                label={labels.projectedRunway}
                value={
                  result.projected_runway_days != null
                    ? `${result.projected_runway_days} ${labels.days}`
                    : result.projected_runway_months != null
                      ? `${result.projected_runway_months} ${labels.months}`
                      : labels.insufficient
                }
              />
              <Row
                label={labels.riskChange}
                value={labels.riskChangeLabels[result.risk_change ?? 'unknown'] ?? result.risk_change ?? '—'}
              />
              {result.confidence ? (
                <Row
                  label={labels.confidence}
                  value={labels.confidenceLevels[result.confidence] ?? result.confidence}
                />
              ) : null}
              {result.impact_summary ? (
                <p className="text-white/65">{result.impact_summary}</p>
              ) : null}
            </>
          )}
        </div>
      ) : null}
    </div>
  )
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-3">
      <span className="text-white/45">{label}</span>
      <span className="font-medium tabular-nums">{value}</span>
    </div>
  )
}
