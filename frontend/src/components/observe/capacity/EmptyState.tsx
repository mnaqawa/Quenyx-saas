import type { ReactNode } from 'react'

export type EmptyStateVariant = 'default' | 'locked' | 'error' | 'search'

interface EmptyStateProps {
  title: string
  description?: string
  icon?: ReactNode
  variant?: EmptyStateVariant
  primaryAction?: ReactNode
  secondaryAction?: ReactNode
  /** @deprecated use primaryAction */
  action?: ReactNode
}

function DefaultIcon({ variant }: { variant: EmptyStateVariant }) {
  if (variant === 'locked') {
    return (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <rect x="3" y="11" width="18" height="11" rx="2" />
        <path d="M7 11V7a5 5 0 0 1 10 0v4" />
      </svg>
    )
  }
  if (variant === 'error') {
    return (
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <circle cx="12" cy="12" r="10" />
        <line x1="12" y1="8" x2="12" y2="12" />
        <line x1="12" y1="16" x2="12.01" y2="16" />
      </svg>
    )
  }
  return (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
      <path d="M3 3v18h18" />
      <path d="M7 16l4-8 4 5 5-9" />
    </svg>
  )
}

export function EmptyState({
  title,
  description,
  icon,
  variant = 'default',
  primaryAction,
  secondaryAction,
  action,
}: EmptyStateProps) {
  const primary = primaryAction ?? action

  return (
    <div
      className="flex flex-col items-center justify-center rounded-xl border border-white/10 bg-white/[0.03] px-6 py-12 text-center"
      role="status"
    >
      <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-2xl border border-white/10 bg-white/[0.04] text-white/40">
        {icon ?? <DefaultIcon variant={variant} />}
      </div>
      <p className="text-sm font-semibold text-white/90">{title}</p>
      {description ? <p className="mt-2 max-w-md text-xs leading-relaxed text-white/50">{description}</p> : null}
      {primary || secondaryAction ? (
        <div className="mt-5 flex flex-wrap items-center justify-center gap-2">
          {primary}
          {secondaryAction}
        </div>
      ) : null}
    </div>
  )
}
