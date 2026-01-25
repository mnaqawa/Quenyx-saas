import React from 'react'

interface StatusBadgeProps {
  status: 'healthy' | 'degraded' | 'critical' | 'running' | 'stopped' | 'warning' | 'connected' | 'error' | 'syncing' | 'completed' | 'processing'
  label: string
  icon?: React.ReactNode
}

export function StatusBadge({ status, label, icon }: StatusBadgeProps) {
  const statusStyles = {
    healthy: 'bg-emerald-500/20 text-emerald-200 border-emerald-500/30',
    running: 'bg-emerald-500/20 text-emerald-200 border-emerald-500/30',
    connected: 'bg-sky-500/20 text-sky-200 border-sky-500/30',
    completed: 'bg-sky-500/20 text-sky-200 border-sky-500/30',
    degraded: 'bg-yellow-500/20 text-yellow-200 border-yellow-500/30',
    warning: 'bg-yellow-500/20 text-yellow-200 border-yellow-500/30',
    critical: 'bg-rose-500/20 text-rose-200 border-rose-500/30',
    stopped: 'bg-gray-500/20 text-gray-200 border-gray-500/30',
    error: 'bg-rose-500/20 text-rose-200 border-rose-500/30',
    syncing: 'bg-purple-500/20 text-purple-200 border-purple-500/30',
    processing: 'bg-purple-500/20 text-purple-200 border-purple-500/30',
    failed: 'bg-rose-500/20 text-rose-200 border-rose-500/30',
  }

  return (
    <span
      className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-[10px] font-medium ${statusStyles[status]}`}
    >
      {icon}
      {label}
    </span>
  )
}
