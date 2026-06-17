import type { CapacityStructuredAdvisor } from '../../../types/observe'

interface AdvisorSectionProps {
  advisor: CapacityStructuredAdvisor | null | undefined
  labels: {
    title: string
    disabled: string
    findings: string
    businessImpact: string
    recommendedAction: string
    confidence: string
    dataUsed: string
    samplesLabel: string
    askAi: string
    confidenceLevels: Record<string, string>
  }
  onAskAi?: () => void
}

export function AdvisorSection({ advisor, labels, onAskAi }: AdvisorSectionProps) {
  const available = advisor?.available ?? false

  return (
    <div className="rounded-2xl border border-orange-500/20 bg-orange-500/5 p-5 text-white">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-semibold text-orange-100">{labels.title}</h3>
        {available && onAskAi ? (
          <button
            type="button"
            onClick={onAskAi}
            className="rounded-lg border border-orange-500/40 bg-orange-500/20 px-3 py-1 text-xs font-semibold text-orange-100 transition hover:bg-orange-500/30"
          >
            {labels.askAi}
          </button>
        ) : null}
      </div>

      {!available ? (
        <p className="text-sm text-white/55">{labels.disabled}</p>
      ) : (
        <div className="grid gap-4 md:grid-cols-2">
          <Section title={labels.findings} items={advisor?.findings ?? []} />
          <Section title={labels.businessImpact} items={advisor?.business_impact ?? []} />
          <Section title={labels.recommendedAction} items={advisor?.recommended_actions ?? []} />
          <div className="space-y-3 text-xs">
            <div>
              <p className="text-white/45">{labels.confidence}</p>
              <p className="mt-1 font-medium">
                {labels.confidenceLevels[advisor?.confidence ?? 'no_data']}
              </p>
            </div>
            <div>
              <p className="text-white/45">{labels.dataUsed}</p>
              <p className="mt-1 text-white/70">
                {advisor?.data_used?.history_samples != null
                  ? `${advisor.data_used.history_samples} ${labels.samplesLabel}`
                  : '—'}
              </p>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

function Section({ title, items }: { title: string; items: string[] }) {
  if (items.length === 0) return null
  return (
    <div>
      <p className="mb-2 text-xs font-semibold text-white/55">{title}</p>
      <ul className="list-disc space-y-1 ps-4 text-xs text-white/75">
        {items.map((item) => (
          <li key={item}>{item}</li>
        ))}
      </ul>
    </div>
  )
}
