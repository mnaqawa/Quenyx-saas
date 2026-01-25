interface ProgressBarProps {
  value: number
  label?: string
  showValue?: boolean
  className?: string
}

export function ProgressBar({ value, label, showValue = true, className = '' }: ProgressBarProps) {
  const percentage = Math.min(Math.max(value, 0), 100)
  const colorClass = percentage > 90 ? 'bg-rose-500' : percentage > 70 ? 'bg-yellow-500' : 'bg-sky-500'

  return (
    <div className={className}>
      {label && (
        <div className="mb-1 flex items-center justify-between text-xs">
          <span className="text-white/70">{label}</span>
          {showValue && <span className="text-white/70">{percentage}%</span>}
        </div>
      )}
      <div className="h-2 w-full rounded-full bg-white/5">
        <div
          className={`h-2 rounded-full transition-all ${colorClass}`}
          style={{ width: `${percentage}%` }}
        />
      </div>
    </div>
  )
}
