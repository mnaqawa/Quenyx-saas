import React from 'react'

interface PageHeaderProps {
  title: string
  subtitle: string
  actions?: React.ReactNode
}

export function PageHeader({ title, subtitle, actions }: PageHeaderProps) {
  return (
    <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
      <div className="flex-1 min-w-0">
        <h1 className="text-2xl font-semibold text-white">{title}</h1>
        <p className="mt-1 text-sm text-white/60">{subtitle}</p>
      </div>
      {actions && (
        <div className="flex flex-wrap items-center gap-2 sm:flex-nowrap sm:ml-4">
          {actions}
        </div>
      )}
    </div>
  )
}
