import { useState } from 'react'
import { useLanguage } from '../../../i18n/LanguageContext'
import { useAiWorkspaceUuid } from '../../../hooks/useAiWorkspace'
import { OperationsCopilotDrawer } from './OperationsCopilotDrawer'

interface QuenyxAiButtonProps {
  /** Visible label, e.g. t('ai.action.explain'). */
  label: string
  /** The grounded question to seed the copilot with (built from the resource context). */
  question: string
  /** Optional drawer title. */
  title?: string
  size?: 'sm' | 'md'
  className?: string
}

/**
 * Sprint 21 — contextual "✨ Quenyx AI" action. Drops onto any QynSight surface (host, service,
 * alert, capacity, infrastructure) and opens the Monitoring Copilot drawer with a grounded question.
 * Keeps the UI uncluttered (a single sparkle action) and reuses the shared Quenyx AI conversation.
 * Renders nothing when no workspace UUID is available.
 */
export function QuenyxAiButton({ label, question, title, size = 'sm', className = '' }: QuenyxAiButtonProps) {
  const { t } = useLanguage()
  const { workspaceUuid, hasWorkspace } = useAiWorkspaceUuid()
  const [open, setOpen] = useState(false)

  if (!hasWorkspace || !workspaceUuid) return null

  const sizeClasses = size === 'sm' ? 'px-2.5 py-1 text-xs' : 'px-3.5 py-2 text-sm'

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        title={t('opsIntel.action.tooltip')}
        className={`inline-flex items-center gap-1 rounded-full border border-amber-400/40 bg-amber-400/10 font-semibold text-amber-100 transition hover:bg-amber-400/20 ${sizeClasses} ${className}`}
      >
        <span aria-hidden>✨</span>
        {label}
      </button>
      <OperationsCopilotDrawer
        open={open}
        onClose={() => setOpen(false)}
        workspaceUuid={workspaceUuid}
        seedQuestion={open ? question : null}
        title={title}
      />
    </>
  )
}
