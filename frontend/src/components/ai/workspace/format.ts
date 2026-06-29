/** Locale-aware number formatting helper. */
export function formatNumber(n: number | null | undefined): string {
  if (n === null || n === undefined) return '0'
  return new Intl.NumberFormat().format(n)
}

/** Render an ISO timestamp as a readable local string (or em dash). */
export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString()
}
