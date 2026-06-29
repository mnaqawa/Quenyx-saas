import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useLanguage } from '../../i18n/LanguageContext'
import { aiWorkspaceService } from '../../services/aiWorkspaceService'
import { useAiResource, useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { AiView, Card, StatTile } from '../../components/ai/workspace/shared'
import { formatDateTime, formatNumber } from '../../components/ai/workspace/format'

export default function AiOverview() {
  const { t } = useLanguage()
  const { hasWorkspace } = useAiWorkspaceUuid()
  const { data, loading, error, reload } = useAiResource((uuid) => aiWorkspaceService.getSummary(uuid))

  return (
    <AiView hasWorkspace={hasWorkspace} loading={loading} error={error} data={data} onRetry={reload}>
      {(res) => {
        const s = res.summary
        return (
          <div className="space-y-6">
            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
              <StatTile label={t('aiWorkspace.overview.conversations')} value={formatNumber(s.conversation_count)} />
              <StatTile label={t('aiWorkspace.overview.messages')} value={formatNumber(s.message_count)} />
              <StatTile label={t('aiWorkspace.overview.totalTokens')} value={formatNumber(s.total_tokens)} />
              <StatTile label={t('aiWorkspace.overview.templates')} value={formatNumber(s.template_count)} />
            </div>

            <Card className="space-y-3">
              <h2 className="text-sm font-semibold text-white">{t('aiWorkspace.overview.status')}</h2>
              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <Row label={t('aiWorkspace.overview.aiEnabled')} value={s.ai_enabled ? t('aiWorkspace.common.on') : t('aiWorkspace.common.off')} />
                <Row label={t('aiWorkspace.overview.defaultProvider')} value={s.default_provider} />
                <Row label={t('aiWorkspace.overview.configuredProviders')} value={formatNumber(s.configured_provider_count)} />
                <Row label={t('aiWorkspace.overview.promptTokens')} value={formatNumber(s.prompt_tokens)} />
                <Row label={t('aiWorkspace.overview.completionTokens')} value={formatNumber(s.completion_tokens)} />
                <Row label={t('aiWorkspace.overview.lastActivity')} value={formatDateTime(s.last_activity_at)} />
              </div>
              <p className="text-xs text-white/40">{t('aiWorkspace.overview.yourRole')}: {res.permissions.role}</p>
            </Card>

            <div className="flex flex-wrap gap-3">
              <Link to="/ai-workspace/chat" className="rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white hover:bg-sky-400">
                {t('aiWorkspace.overview.startChat')}
              </Link>
              <Link to="/ai-workspace/capabilities" className="rounded-full border border-white/15 px-4 py-2 text-xs font-semibold text-white/80 hover:bg-white/10">
                {t('aiWorkspace.nav.capabilities')}
              </Link>
            </div>
          </div>
        )
      }}
    </AiView>
  )
}

function Row({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="flex items-center justify-between rounded-lg border border-white/5 bg-white/5 px-3 py-2">
      <span className="text-xs text-white/50">{label}</span>
      <span className="text-sm font-medium text-white">{value}</span>
    </div>
  )
}
