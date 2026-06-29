import { useLanguage } from '../../i18n/LanguageContext'
import { aiWorkspaceService } from '../../services/aiWorkspaceService'
import { useAiResource, useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { AiView, Card, formatDateTime } from '../../components/ai/workspace/shared'

export default function AiActivity() {
  const { t } = useLanguage()
  const { hasWorkspace } = useAiWorkspaceUuid()
  const { data, loading, error, reload } = useAiResource((uuid) => aiWorkspaceService.getActivity(uuid))

  return (
    <AiView
      hasWorkspace={hasWorkspace}
      loading={loading}
      error={error}
      data={data}
      onRetry={reload}
      isEmpty={(res) => res.items.length === 0}
      emptyTitle={t('aiWorkspace.activity.emptyTitle')}
      emptyDescription={t('aiWorkspace.activity.emptyDescription')}
    >
      {(res) => (
        <Card className="p-0">
          <ul className="divide-y divide-white/5">
            {res.items.map((item) => (
              <li key={item.uuid} className="flex items-start justify-between gap-4 px-4 py-3">
                <div className="min-w-0">
                  <p className="text-sm font-medium text-white">{item.action}</p>
                  {item.provider ? <p className="text-xs text-white/40">{item.provider}</p> : null}
                </div>
                <span className="shrink-0 text-xs text-white/40">{formatDateTime(item.occurred_at)}</span>
              </li>
            ))}
          </ul>
        </Card>
      )}
    </AiView>
  )
}
