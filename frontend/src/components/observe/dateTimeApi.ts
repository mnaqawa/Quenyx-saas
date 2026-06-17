function pad2(n: number): string {
  return String(n).padStart(2, '0')
}

function parseDateTimeValue(value: string): Date | null {
  const normalized = value.includes('T') ? value : value.replace(' ', 'T')
  const d = new Date(normalized)
  return Number.isNaN(d.getTime()) ? null : d
}

/** Format a datetime field value for alert history API filters. */
export function toApiDateTime(value?: string): string | undefined {
  if (!value) return undefined
  const d = parseDateTimeValue(value)
  if (!d) return undefined
  return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())} ${pad2(d.getHours())}:${pad2(d.getMinutes())}:${pad2(d.getSeconds())}`
}
