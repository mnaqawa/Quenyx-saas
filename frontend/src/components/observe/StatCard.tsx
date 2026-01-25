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
    <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
      <div className="flex items-start justify-between">
        <div className="flex-1">
          <p className="text-xs text-white/50">{title}</p>
          <p className="mt-1 text-2xl font-semibold">{value}</p>
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
        {icon && <div className="ml-4">{icon}</div>}
      </div>
    </div>
  )
}
