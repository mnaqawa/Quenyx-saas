import { Link } from 'react-router-dom'
import { useLanguage } from '../../i18n/LanguageContext'
import { aiWorkspaceService } from '../../services/aiWorkspaceService'
import { useAiResource, useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { AiView, Card } from '../../components/ai/workspace/shared'
import { formatDateTime, formatNumber } from '../../components/ai/workspace/format'

export default function AiConversations() {
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
      emptyTitle={t('aiWorkspace.conversations.emptyTitle')}
      emptyDescription={t('aiWorkspace.conversations.emptyDescription')}
    >
      {(rows) => (
        <div className="space-y-3">
          {rows.map((c) => (
            <Link key={c.uuid} to={`/ai-workspace/chat/${c.uuid}`}>
              <Card className="flex items-center justify-between transition hover:border-sky-400/40">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium text-white">
                    {c.title?.trim() || `${c.uuid.slice(0, 8)}…`} · {c.provider}
                  </p>
                  <p className="text-xs text-white/50">
                    {t('aiWorkspace.conversations.messages')}: {formatNumber(c.message_count)} ·{' '}
                    {t('aiWorkspace.conversations.tokens')}: {formatNumber(c.total_tokens)}
                  </p>
                </div>
                <span className="text-xs text-white/40">{formatDateTime(c.updated_at)}</span>
              </Card>
            </Link>
          ))}
        </div>
      )}
    </AiView>
  )
}
