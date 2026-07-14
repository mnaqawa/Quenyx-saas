/** Format seconds as compact Nagios-style duration (time in current state). */
export function formatObserveDuration(seconds: number): string {
  const safe = Number.isFinite(seconds) ? Math.max(0, Math.floor(seconds)) : 0
  const days = Math.floor(safe / 86400)
  const hours = Math.floor((safe % 86400) / 3600)
  const minutes = Math.floor((safe % 3600) / 60)
  const secs = safe % 60

  const parts: string[] = []
  if (days > 0) parts.push(`${days}d`)
  if (hours > 0 || days > 0) parts.push(`${hours}h`)
  if (minutes > 0 || parts.length > 0) parts.push(`${minutes}m`)
  if (parts.length === 0 || (secs > 0 && parts.length < 3)) parts.push(`${secs}s`)

  return parts.join(' ') || '0s'
}

/** Prefer live age from last state change; fall back to API durationSec. */
export function resolveObserveDurationSec(
  durationSec: number | null | undefined,
  lastStateChangeAt: string | null | undefined,
  nowMs: number = Date.now(),
): number {
  if (lastStateChangeAt) {
    const t = new Date(lastStateChangeAt).getTime()
    if (!Number.isNaN(t) && t <= nowMs) {
      return Math.max(0, Math.floor((nowMs - t) / 1000))
    }
  }
  return typeof durationSec === 'number' && Number.isFinite(durationSec) ? Math.max(0, Math.floor(durationSec)) : 0
}
