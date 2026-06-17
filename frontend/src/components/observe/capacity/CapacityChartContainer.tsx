import type { ReactNode } from 'react'
import { EmptyState } from './EmptyState'

interface CapacityChartContainerProps {
  title: string
  subtitle?: string
  hasData: boolean
  emptyTitle: string
  emptyDescription?: string
  children: ReactNode
  badge?: string
}

export function CapacityChartContainer({
  title,
  subtitle,
  hasData,
  emptyTitle,
  emptyDescription,
  children,
  badge,
}: CapacityChartContainerProps) {
  return (
    <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
      <div className="mb-4 flex flex-wrap items-start justify-between gap-2">
        <div>
          <h3 className="text-sm font-semibold">{title}</h3>
          {subtitle ? <p className="text-xs text-white/50">{subtitle}</p> : null}
        </div>
        {badge ? (
          <span className="rounded bg-orange-500/20 px-2 py-0.5 text-[10px] font-semibold uppercase text-orange-100">
            {badge}
          </span>
        ) : null}
      </div>
      <div className="h-[280px] w-full">
        {hasData ? children : <EmptyState title={emptyTitle} description={emptyDescription} />}
      </div>
    </div>
  )
}
