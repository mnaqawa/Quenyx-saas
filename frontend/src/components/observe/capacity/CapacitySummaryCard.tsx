import type { CapacityStatus } from '../../../types/observe'

interface CapacitySummaryCardProps {
  title: string
  value: string
  status: CapacityStatus
  statusLabel: string
  detail?: string
}

const statusClass: Record<CapacityStatus, string> = {
  critical: 'border-rose-500/30 bg-rose-500/15 text-rose-200',
  warning: 'border-amber-500/30 bg-amber-500/15 text-amber-200',
  healthy: 'border-emerald-500/30 bg-emerald-500/15 text-emerald-200',
  insufficient_data: 'border-white/15 bg-white/5 text-white/50',
}

export function CapacitySummaryCard({ title, value, status, statusLabel, detail }: CapacitySummaryCardProps) {
  return (
    <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
      <p className="text-xs text-white/50">{title}</p>
      <p className="mt-1 text-2xl font-semibold tabular-nums">{value}</p>
      <div className="mt-2 flex flex-wrap items-center gap-2">
        <span className={`rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase ${statusClass[status]}`}>
          {statusLabel}
        </span>
        {detail ? <span className="text-xs text-white/50">{detail}</span> : null}
      </div>
    </div>
  )
}
