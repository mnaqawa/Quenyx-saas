import { Link, useParams } from 'react-router-dom'
import { useLanguage } from '../../i18n/LanguageContext'
import { aiWorkspaceService } from '../../services/aiWorkspaceService'
import { useAiResource, useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { AiView, Card } from '../../components/ai/workspace/shared'
import { formatDateTime, formatNumber } from '../../components/ai/workspace/format'

export default function AiConversationDetail() {
  const { t } = useLanguage()
  const { uuid = '' } = useParams()
  const { hasWorkspace } = useAiWorkspaceUuid()
  const { data, loading, error, reload } = useAiResource(
    (wsUuid) => aiWorkspaceService.getConversation(wsUuid, uuid),
    [uuid]
  )

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <Link to="/ai-workspace/conversations" className="text-xs text-sky-300 hover:underline">
          ← {t('aiWorkspace.nav.conversations')}
        </Link>
        <Link
          to={`/ai-workspace/chat/${uuid}`}
          className="rounded-full bg-sky-500 px-3 py-1 text-xs font-semibold text-white hover:bg-sky-400"
        >
          {t('aiWorkspace.chat.continueChat')}
        </Link>
      </div>
      <AiView hasWorkspace={hasWorkspace} loading={loading} error={error} data={data} onRetry={reload}>
        {(c) => (
          <div className="space-y-4">
            <Card className="flex flex-wrap items-center gap-x-6 gap-y-1 text-xs text-white/60">
              <span>{t('aiWorkspace.conversations.provider')}: <span className="text-white">{c.provider}</span></span>
              <span>{t('aiWorkspace.conversations.tokens')}: <span className="text-white">{formatNumber(c.total_tokens)}</span></span>
              <span>{t('aiWorkspace.conversations.messages')}: <span className="text-white">{formatNumber(c.message_count)}</span></span>
              <span>{formatDateTime(c.created_at)}</span>
            </Card>

            {(c.messages ?? []).length === 0 ? (
              <p className="text-sm text-white/50">{t('aiWorkspace.conversations.noMessages')}</p>
            ) : (
              <div className="space-y-3">
                {(c.messages ?? []).map((m) => (
                  <div key={m.uuid} className={m.role === 'user' ? 'text-end' : 'text-start'}>
                    <span
                      className={[
                        'inline-block max-w-[80%] whitespace-pre-wrap rounded-2xl px-4 py-2 text-sm',
                        m.role === 'user' ? 'bg-sky-500 text-white' : 'bg-white/10 text-white/90',
                      ].join(' ')}
                    >
                      {m.content ?? <em className="text-white/40">{t('aiWorkspace.conversations.contentHidden')}</em>}
                    </span>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}
      </AiView>
    </div>
  )
}
