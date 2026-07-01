import { Link } from 'react-router-dom'
import { useLanguage } from '../../i18n/LanguageContext'
import { aiWorkspaceService } from '../../services/aiWorkspaceService'
import { useAiResource, useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { AiView, Card } from '../../components/ai/workspace/shared'
import { formatDateTime, formatNumber } from '../../components/ai/workspace/format'

/**
 * AI History — read-only chronological log of every conversation in the workspace (same source as
 * Conversations, ordered most-recent first). No fabricated entries; empty until conversations exist.
 */
export default function AiHistory() {
  const { t } = useLanguage()
  const { hasWorkspace } = useAiWorkspaceUuid()
  const { data, loading, error, reload } = useAiResource((uuid) => aiWorkspaceService.listConversations(uuid))

  return (
    <AiView
      hasWorkspace={hasWorkspace}
      loading={loading}
      error={error}
      data={data}
      onRetry={reload}
      isEmpty={(rows) => rows.length === 0}
      emptyTitle={t('aiWorkspace.history.emptyTitle')}
      emptyDescription={t('aiWorkspace.history.emptyDescription')}
    >
      {(rows) => (
        <Card className="divide-y divide-white/5 p-0">
          {rows.map((c) => (
            <Link
              key={c.uuid}
              to={`/ai-workspace/chat/${c.uuid}`}
              className="flex items-center justify-between px-4 py-3 transition hover:bg-white/5"
            >
              <div className="min-w-0">
                <p className="truncate text-sm text-white">
                  {c.title?.trim() || `${c.uuid.slice(0, 8)}…`} · {c.provider}
                </p>
                <p className="text-xs text-white/40">
                  {formatNumber(c.message_count)} {t('aiWorkspace.conversations.messages')} · {formatNumber(c.total_tokens)} {t('aiWorkspace.conversations.tokens')}
                </p>
              </div>
              <span className="text-xs text-white/40">{formatDateTime(c.created_at)}</span>
            </Link>
          ))}
        </Card>
      )}
    </AiView>
  )
}
