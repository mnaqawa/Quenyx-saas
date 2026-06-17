import type { CollectingHistoricalDataPanelProps } from '../../lib/collectingHistoricalDataUtils'

export function CollectingHistoricalDataPanel({
  title,
  description,
  samplesLabel,
  samplesValue,
  forecastLabel,
  forecastValue,
  confidenceLabel,
  confidenceValue,
}: CollectingHistoricalDataPanelProps) {
  return (
    <div className="rounded-2xl border border-sky-500/20 bg-sky-500/5 p-6">
      <h3 className="text-base font-semibold text-white">{title}</h3>
      <p className="mt-2 max-w-2xl text-sm text-white/60">{description}</p>
      <dl className="mt-6 grid gap-4 sm:grid-cols-3">
        <div className="rounded-lg border border-white/10 bg-[#0f151d]/80 p-4">
          <dt className="text-xs text-white/50">{samplesLabel}</dt>
          <dd className="mt-1 text-2xl font-semibold tabular-nums text-white">{samplesValue}</dd>
        </div>
        <div className="rounded-lg border border-white/10 bg-[#0f151d]/80 p-4">
          <dt className="text-xs text-white/50">{forecastLabel}</dt>
          <dd className="mt-1 text-sm font-medium text-white">{forecastValue}</dd>
        </div>
        <div className="rounded-lg border border-white/10 bg-[#0f151d]/80 p-4">
          <dt className="text-xs text-white/50">{confidenceLabel}</dt>
          <dd className="mt-1 text-sm font-medium text-white">{confidenceValue}</dd>
        </div>
      </dl>
    </div>
  )
}
