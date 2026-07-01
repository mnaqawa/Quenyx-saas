import React from 'react'

interface StatCardProps {
  title: string
  value: string
  detail?: string
  trend?: {
    direction: 'up' | 'down'
    value: string
    label: string
  }
  percentage?: number
  icon?: React.ReactNode
}

export function StatCard({ title, value, detail, trend, percentage, icon }: StatCardProps) {
  return (
    <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white transition hover:border-white/15">
      <div className="flex items-start justify-between gap-3">
        {icon ? (
          <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-white/10 bg-white/[0.04] text-white/55">
            {icon}
          </div>
        ) : null}
        <div className="min-w-0 flex-1">
          <p className="text-xs text-white/50">{title}</p>
          <p className="mt-1 text-2xl font-semibold tabular-nums">{value}</p>
          {detail && <p className="mt-1 text-xs text-white/60">{detail}</p>}
          {trend && (
            <div className="mt-2 flex items-center gap-1 text-xs">
              <span className={trend.direction === 'up' ? 'text-rose-200' : 'text-emerald-200'}>
                {trend.direction === 'up' ? '▲' : '▼'} {trend.value}
              </span>
              <span className="text-white/60">{trend.label}</span>
            </div>
          )}
          {percentage !== undefined && (
            <div className="mt-3 h-2 w-full rounded-full bg-white/5">
              <div
                className="h-2 rounded-full bg-sky-500 transition-all"
                style={{ width: `${Math.min(percentage, 100)}%` }}
              />
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
