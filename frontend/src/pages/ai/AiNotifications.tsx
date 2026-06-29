import { useLanguage } from '../../i18n/LanguageContext'
import { aiWorkspaceService } from '../../services/aiWorkspaceService'
import { useAiResource, useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { AiView, Card, formatDateTime } from '../../components/ai/workspace/shared'

export default function AiNotifications() {
  const { t } = useLanguage()
  const { hasWorkspace } = useAiWorkspaceUuid()
  const { data, loading, error, reload } = useAiResource((uuid) => aiWorkspaceService.getNotifications(uuid))

  return (
    <AiView
      hasWorkspace={hasWorkspace}
      loading={loading}
      error={error}
      data={data}
      onRetry={reload}
      isEmpty={(res) => res.items.length === 0}
      emptyTitle={t('aiWorkspace.notifications.emptyTitle')}
      emptyDescription={t('aiWorkspace.notifications.emptyDescription')}
    >
      {(res) => (
        <div className="space-y-3">
          {res.items.map((n) => (
            <Card key={n.uuid} className="flex items-center justify-between">
              <span className="text-sm text-white">{t(`aiWorkspace.notifications.type.${n.type}`)}</span>
              <span className="text-xs text-white/40">{formatDateTime(n.created_at)}</span>
            </Card>
          ))}
        </div>
      )}
    </AiView>
  )
}
