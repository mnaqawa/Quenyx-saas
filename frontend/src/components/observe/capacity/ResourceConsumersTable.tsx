import type { CapacityConsumer } from '../../../types/observe'
import { EmptyState } from './EmptyState'

interface ResourceConsumersTableProps {
  title: string
  consumers: CapacityConsumer[]
  emptyTitle: string
  valueLabel: string
  hostLabel: string
}

function barColor(pct: number): string {
  if (pct >= 90) return 'bg-rose-500'
  if (pct >= 75) return 'bg-amber-500'
  return 'bg-sky-500'
}

export function ResourceConsumersTable({
  title,
  consumers,
  emptyTitle,
  valueLabel,
  hostLabel,
}: ResourceConsumersTableProps) {
  if (consumers.length === 0) {
    return (
      <div className="min-w-0 rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
        <h3 className="mb-4 text-sm font-semibold">{title}</h3>
        <EmptyState title={emptyTitle} />
      </div>
    )
  }

  return (
    <div className="min-w-0 rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
      <h3 className="mb-4 text-sm font-semibold">{title}</h3>
      <div className="min-w-0 space-y-3">
        {consumers.map((row) => {
          const pct = Math.min(100, Math.max(0, Math.round(row.value_pct)))
          return (
            <div key={`${row.host}-${row.metric}`} className="min-w-0">
              <div className="flex min-w-0 items-center justify-between gap-2 text-xs">
                <span className="min-w-0 truncate font-medium" title={row.host}>
                  {row.host}
                </span>
                <span className="shrink-0 tabular-nums text-white/70">
                  {pct}% <span className="sr-only">{valueLabel}</span>
                </span>
              </div>
              <div
                className="mt-1.5 h-2 w-full max-w-full overflow-hidden rounded-full bg-white/10"
                role="progressbar"
                aria-valuenow={pct}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-label={`${hostLabel} ${row.host} ${valueLabel}`}
              >
                <div
                  className={`h-full max-w-full rounded-full transition-all ${barColor(pct)}`}
                  style={{ width: `${pct}%` }}
                />
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}
