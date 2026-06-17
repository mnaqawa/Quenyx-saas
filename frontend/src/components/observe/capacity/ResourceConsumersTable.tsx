import type { CapacityConsumer } from '../../../types/observe'
import { EmptyState } from './EmptyState'

interface ResourceConsumersTableProps {
  title: string
  consumers: CapacityConsumer[]
  emptyTitle: string
  valueLabel: string
}

export function ResourceConsumersTable({ title, consumers, emptyTitle, valueLabel }: ResourceConsumersTableProps) {
  if (consumers.length === 0) {
    return (
      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
        <h3 className="mb-4 text-sm font-semibold">{title}</h3>
        <EmptyState title={emptyTitle} />
      </div>
    )
  }

  return (
    <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
      <h3 className="mb-4 text-sm font-semibold">{title}</h3>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[320px] text-left text-sm">
          <thead>
            <tr className="border-b border-white/10 text-[11px] uppercase tracking-wider text-white/50">
              <th className="pb-2 pr-4 font-medium">Host</th>
              <th className="pb-2 font-medium">{valueLabel}</th>
            </tr>
          </thead>
          <tbody>
            {consumers.map((row) => (
              <tr key={`${row.host}-${row.metric}`} className="border-b border-white/5">
                <td className="py-2.5 pr-4 font-medium">{row.host}</td>
                <td className="py-2.5 tabular-nums text-white/80">{Math.round(row.value_pct)}%</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
