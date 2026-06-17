import type { CapacityAdvisor } from '../../../types/observe'
import { EmptyState } from './EmptyState'

interface AICapacityAdvisorProps {
  advisor: CapacityAdvisor | null
  title: string
  emptyTitle: string
  onAskAi?: () => void
  askAiLabel?: string
}

export function AICapacityAdvisor({ advisor, title, emptyTitle, onAskAi, askAiLabel }: AICapacityAdvisorProps) {
  return (
    <div className="rounded-2xl border border-orange-500/20 bg-orange-500/5 p-5 text-white">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-semibold text-orange-100">{title}</h3>
        {onAskAi && askAiLabel ? (
          <button
            type="button"
            onClick={onAskAi}
            className="rounded-lg border border-orange-500/40 bg-orange-500/20 px-3 py-1 text-xs font-semibold text-orange-100 transition hover:bg-orange-500/30"
          >
            {askAiLabel}
          </button>
        ) : null}
      </div>
      {advisor ? (
        <div className="space-y-2 text-sm">
          <p className="text-white/80">{advisor.summary}</p>
          <ul className="list-disc space-y-1 ps-5 text-xs text-white/65">
            {advisor.bullets.map((bullet) => (
              <li key={bullet}>{bullet}</li>
            ))}
          </ul>
        </div>
      ) : (
        <EmptyState title={emptyTitle} />
      )}
    </div>
  )
}
