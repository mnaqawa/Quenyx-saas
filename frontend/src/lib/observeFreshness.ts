/** Matches backend default `observe.stale_threshold_seconds`. */
export const OBSERVE_STALE_THRESHOLD_SECONDS = 300

export function getPollAgeSeconds(lastPollAt: string | null | undefined): number | null {
  if (lastPollAt == null || String(lastPollAt).trim() === '') return null
  const ts = new Date(lastPollAt).getTime()
  if (Number.isNaN(ts)) return null
  return Math.max(0, Math.floor((Date.now() - ts) / 1000))
}

export function isObservePollStale(
  lastPollAt: string | null | undefined,
  thresholdSec = OBSERVE_STALE_THRESHOLD_SECONDS,
): boolean {
  const ageSec = getPollAgeSeconds(lastPollAt)
  if (ageSec === null) return false
  return ageSec > thresholdSec
}

/**
 * Whether to show the yellow stale-data banner (engine unreachable uses a separate branch).
 * Re-validates the server `stale` flag against last_poll_at to avoid false positives.
 */
export function shouldShowObserveStaleBanner(data: {
  stale?: boolean
  last_poll_at?: string | null
}): boolean {
  if (!data.stale) return false
  if (!data.last_poll_at) return false
  return isObservePollStale(data.last_poll_at)
}
