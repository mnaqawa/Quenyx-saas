import React from 'react'
import { Link } from 'react-router-dom'
import { useLanguage } from '../../../i18n/LanguageContext'
import { EmptyState } from '../../observe/capacity/EmptyState'

/** Standard surface card matching the platform dark theme. */
export function Card({ children, className = '' }: { children: React.ReactNode; className?: string }) {
  return (
    <div className={`rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white ${className}`}>{children}</div>
  )
}

/** Compact metric tile. */
export function StatTile({ label, value, hint }: { label: string; value: React.ReactNode; hint?: string }) {
  return (
    <Card className="flex flex-col gap-1">
      <span className="text-xs font-medium uppercase tracking-wide text-white/50">{label}</span>
      <span className="text-2xl font-semibold text-white">{value}</span>
      {hint ? <span className="text-xs text-white/40">{hint}</span> : null}
    </Card>
  )
}

/** Loading skeleton row. */
export function AiLoading({ label }: { label?: string }) {
  const { t } = useLanguage()
  return <div className="text-sm text-white/60">{label ?? t('aiWorkspace.common.loading')}</div>
}

/** Inline error banner with optional retry. */
export function AiError({ message, onRetry }: { message: string; onRetry?: () => void }) {
  const { t } = useLanguage()
  return (
    <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
      <p>{message}</p>
      {onRetry ? (
        <button
          type="button"
          onClick={onRetry}
          className="mt-2 rounded-full border border-rose-300/40 px-3 py-1 text-xs font-semibold text-rose-50 hover:bg-rose-500/20"
        >
          {t('aiWorkspace.common.retry')}
        </button>
      ) : null}
    </div>
  )
}

/** "Select a workspace" notice shown when no workspace UUID is available. */
export function NoWorkspaceNotice() {
  const { t } = useLanguage()
  return (
    <EmptyState
      title={t('aiWorkspace.noWorkspace.title')}
      description={t('aiWorkspace.noWorkspace.description')}
      action={
        <Link
          to="/app/workspaces"
          className="rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white hover:bg-sky-400"
        >
          {t('aiWorkspace.noWorkspace.action')}
        </Link>
      }
    />
  )
}

interface AiViewProps<T> {
  hasWorkspace: boolean
  loading: boolean
  error: string | null
  data: T | null
  isEmpty?: (data: T) => boolean
  emptyTitle?: string
  emptyDescription?: string
  onRetry?: () => void
  children: (data: T) => React.ReactNode
}

/**
 * Standard state machine for AI workspace panels: no-workspace → loading → error → empty → content.
 */
export function AiView<T>({
  hasWorkspace,
  loading,
  error,
  data,
  isEmpty,
  emptyTitle,
  emptyDescription,
  onRetry,
  children,
}: AiViewProps<T>) {
  const { t } = useLanguage()

  if (!hasWorkspace) return <NoWorkspaceNotice />
  if (loading) return <AiLoading />
  if (error) return <AiError message={error} onRetry={onRetry} />
  if (data === null) return <AiLoading />
  if (isEmpty && isEmpty(data)) {
    return (
      <EmptyState
        title={emptyTitle ?? t('aiWorkspace.common.emptyTitle')}
        description={emptyDescription ?? t('aiWorkspace.common.emptyDescription')}
      />
    )
  }

  return <>{children(data)}</>
}

/** Locale-aware number formatting helper. */
export function formatNumber(n: number | null | undefined): string {
  if (n === null || n === undefined) return '0'
  return new Intl.NumberFormat().format(n)
}

/** Render an ISO timestamp as a readable local string (or em dash). */
export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString()
}
