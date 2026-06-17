import React from 'react'

interface PageHeaderProps {
  title: string
  subtitle: string
  actions?: React.ReactNode
  /** Keep title on one line on desktop (e.g. Infrastructure Map). */
  titleNoWrap?: boolean
}

export function PageHeader({ title, subtitle, actions, titleNoWrap = false }: PageHeaderProps) {
  return (
    <div className="mb-6 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
      <div className="min-w-0 lg:max-w-xl lg:shrink-0">
        <h1
          className={`text-2xl font-semibold text-white ${titleNoWrap ? 'lg:whitespace-nowrap' : ''}`}
        >
          {title}
        </h1>
        <p className="mt-1 max-w-2xl text-sm text-white/60">{subtitle}</p>
      </div>
      {actions ? (
        <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2 lg:justify-end">
          {actions}
        </div>
      ) : null}
    </div>
  )
}
