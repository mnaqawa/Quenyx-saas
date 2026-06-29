import { useLanguage } from '../../i18n/LanguageContext'
import { EmptyState } from '../../components/observe/capacity/EmptyState'
import { useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { NoWorkspaceNotice } from '../../components/ai/workspace/shared'

/**
 * AI Memory — the platform does not yet persist a durable AI memory store. Rather than fabricate
 * data, this surface honestly states that long-term memory is not enabled and points to the real
 * conversation history (which IS persisted). It will be wired to a backend store when one exists.
 */
export default function AiMemory() {
  const { t } = useLanguage()
  const { hasWorkspace } = useAiWorkspaceUuid()

  if (!hasWorkspace) return <NoWorkspaceNotice />

  return (
    <EmptyState
      title={t('aiWorkspace.memory.unsupportedTitle')}
      description={t('aiWorkspace.memory.unsupportedDescription')}
    />
  )
}
