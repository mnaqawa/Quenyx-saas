interface ObserveLoadErrorProps {
  message: string
  retryLabel: string
  onRetry?: () => void
}

export function ObserveLoadError({ message, retryLabel, onRetry }: ObserveLoadErrorProps) {
  return (
    <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-4 text-sm text-rose-100">
      <p>{message}</p>
      {onRetry ? (
        <button
          type="button"
          onClick={onRetry}
          className="mt-3 rounded-lg border border-rose-500/40 bg-rose-500/20 px-3 py-1.5 text-xs font-medium text-rose-50 hover:bg-rose-500/30"
        >
          {retryLabel}
        </button>
      ) : null}
    </div>
  )
}
