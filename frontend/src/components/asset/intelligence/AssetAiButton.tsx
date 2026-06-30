import { useState } from 'react'
import { useLanguage } from '../../../i18n/LanguageContext'
import { useAiWorkspaceUuid } from '../../../hooks/useAiWorkspace'
import { AiCopilotDrawer } from '../../ai/AiCopilotDrawer'
import { assetIntelligenceService } from '../../../services/assetIntelligenceService'

interface AssetAiButtonProps {
  /** Visible label, e.g. t('ai.action.explain'). */
  label: string
  /** The grounded question to seed the Asset Copilot with (built from the asset context). */
  question: string
  title?: string
  size?: 'sm' | 'md'
  className?: string
}

/**
 * Sprint 22 — contextual "✨ Quenyx AI" action for QynAsset. Reuses the exact Sprint 21 UX (a single
 * sparkle action) and the generic {@link AiCopilotDrawer}, grounding the Asset Copilot in real asset
 * evidence and reusing the shared Quenyx AI conversation. Renders nothing without a workspace UUID.
 */
export function AssetAiButton({ label, question, title, size = 'sm', className = '' }: AssetAiButtonProps) {
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
        title={t('assetIntel.action.tooltip')}
        className={`inline-flex items-center gap-1 rounded-full border border-amber-400/40 bg-amber-400/10 font-semibold text-amber-100 transition hover:bg-amber-400/20 ${sizeClasses} ${className}`}
      >
        <span aria-hidden>✨</span>
        {label}
      </button>
      <AiCopilotDrawer
        open={open}
        onClose={() => setOpen(false)}
        workspaceUuid={workspaceUuid}
        copilot={assetIntelligenceService.copilot}
        seedQuestion={open ? question : null}
        title={title ?? t('assetIntel.copilot.title')}
      />
    </>
  )
}
