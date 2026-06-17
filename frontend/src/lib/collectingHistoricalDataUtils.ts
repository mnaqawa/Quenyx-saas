import type { CapacityDiagnostics } from '../types/observe'

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

export interface CollectingHistoricalDataPanelProps {
  title: string
  description: string
  samplesLabel: string
  samplesValue: number
  forecastLabel: string
  forecastValue: string
  confidenceLabel: string
  confidenceValue: string
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
