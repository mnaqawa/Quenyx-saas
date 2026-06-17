import type { CapacityDiagnostics } from '../../types/observe'

const FORECAST_MIN_DAYS = 7

export function daysCollectedFromDiagnostics(diagnostics?: CapacityDiagnostics | null): number | null {
  if (!diagnostics?.oldest_sample_at) return null
  const oldest = new Date(diagnostics.oldest_sample_at)
  if (Number.isNaN(oldest.getTime())) return null
  const ms = Date.now() - oldest.getTime()
  return Math.max(0, Math.floor(ms / 86_400_000))
}

/** Days until 7-day history threshold; null when forecast window is met. */
export function daysUntilForecastReady(diagnostics?: CapacityDiagnostics | null): number | null {
  const collected = daysCollectedFromDiagnostics(diagnostics)
  if (collected === null) return FORECAST_MIN_DAYS
  const remaining = FORECAST_MIN_DAYS - collected
  return remaining > 0 ? remaining : null
}

interface CollectingHistoricalDataPanelProps {
  title: string
  description: string
  samplesLabel: string
  samplesValue: number
  forecastLabel: string
  forecastValue: string
  confidenceLabel: string
  confidenceValue: string
}

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

export function buildCollectingPanelProps(
  diagnostics: CapacityDiagnostics | undefined | null,
  historyPoints: number | undefined,
  dataConfidence: string | undefined,
  t: (key: string) => string,
): CollectingHistoricalDataPanelProps {
  const samples = diagnostics?.total_samples ?? historyPoints ?? 0
  const daysRemaining = daysUntilForecastReady(diagnostics)
  const forecastValue =
    daysRemaining != null
      ? t('cap.collecting.forecastAfterDays').replace('{days}', String(daysRemaining))
      : t('cap.collecting.forecastReady')

  const confidenceKey = dataConfidence?.toLowerCase() ?? 'low'
  const confidenceTranslated = t(`cap.confidence.${confidenceKey}`)
  const confidenceValue =
    confidenceTranslated !== `cap.confidence.${confidenceKey}` ? confidenceTranslated : t('cap.confidence.low')

  return {
    title: t('cap.collecting.title'),
    description: t('cap.collecting.description'),
    samplesLabel: t('cap.collecting.samples'),
    samplesValue: samples,
    forecastLabel: t('cap.collecting.forecastAvailability'),
    forecastValue,
    confidenceLabel: t('cap.collecting.dataConfidence'),
    confidenceValue,
  }
}
