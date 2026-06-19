/** Observe check interval helpers — API/DB uses seconds; UI shows minutes. */

export const DEFAULT_CHECK_INTERVAL_MIN = 5
export const DEFAULT_RETRY_INTERVAL_MIN = 1
export const MIN_CHECK_INTERVAL_SEC = 60

export function intervalSecondsToMinutes(seconds: number | null | undefined): number | null {
  if (seconds == null || Number.isNaN(seconds)) {
    return null
  }
  return Math.max(1, Math.round(seconds / 60))
}

export function intervalMinutesToSeconds(minutes: number | null | undefined): number | null {
  if (minutes == null || Number.isNaN(minutes)) {
    return null
  }
  return Math.max(MIN_CHECK_INTERVAL_SEC, minutes * 60)
}

export function normalizeServiceIntervalsForUi<T extends { check_interval?: number | null; retry_interval?: number | null }>(
  service: T
): T {
  return {
    ...service,
    check_interval: intervalSecondsToMinutes(service.check_interval) ?? undefined,
    retry_interval: intervalSecondsToMinutes(service.retry_interval) ?? undefined,
  }
}

export function serviceIntervalsForApi(service: {
  check_interval?: number | null
  retry_interval?: number | null
}): { check_interval?: number; retry_interval?: number } {
  const check_interval = intervalMinutesToSeconds(service.check_interval ?? null)
  const retry_interval = intervalMinutesToSeconds(service.retry_interval ?? null)

  return {
    ...(check_interval != null ? { check_interval } : {}),
    ...(retry_interval != null ? { retry_interval } : {}),
  }
}
