import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useLanguage } from '../../i18n/LanguageContext'
import { aiWorkspaceService } from '../../services/aiWorkspaceService'
import { useAiResource, useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { AiView, Card, StatTile } from '../../components/ai/workspace/shared'
import { formatDateTime, formatNumber } from '../../components/ai/workspace/format'

/**
 * Quenyx AI — operational overview (RC1.1). Every figure is derived from real backend data
 * (token counts, provider registry/catalog, loaded skills/capabilities, audit activity). When no
 * real provider is configured the dashboard shows an honest "no provider configured" state rather
 * than fabricated availability.
 */
export default function AiOverview() {
  const { t } = useLanguage()
  const { hasWorkspace } = useAiWorkspaceUuid()
  const { data, loading, error, reload } = useAiResource((uuid) => aiWorkspaceService.getSummary(uuid))
  const activity = useAiResource((uuid) => aiWorkspaceService.getActivity(uuid))

  return (
    <AiView hasWorkspace={hasWorkspace} loading={loading} error={error} data={data} onRetry={reload}>
      {(res) => {
        const s = res.summary
        const live = s.ai_enabled && s.has_provider
        return (
          <div className="space-y-6">
            {/* Provider / mode banner */}
            {s.has_provider ? (
              <Card className="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <p className="text-xs uppercase tracking-wide text-white/40">{t('aiWorkspace.overview.mode')}</p>
                  <p className="text-lg font-semibold text-white">
                    {live ? t('aiWorkspace.overview.live') : t('aiWorkspace.overview.safeMode')}
                    <span className="ml-2 text-sm font-normal text-white/50">· {s.default_provider}</span>
                  </p>
                </div>
                <span className={`h-2.5 w-2.5 rounded-full ${live ? 'bg-emerald-400' : 'bg-amber-400'}`} aria-hidden />
              </Card>
            ) : (
              <Card className="space-y-2 border-amber-400/30 bg-amber-400/5">
                <h2 className="text-sm font-semibold text-amber-100">{t('aiWorkspace.overview.noProvider')}</h2>
                <p className="text-xs text-amber-100/80">{t('aiWorkspace.overview.noProviderHint')}</p>
                <Link to="/ai-workspace/providers" className="inline-block rounded-full bg-amber-400/90 px-4 py-2 text-xs font-semibold text-[#1a1205] hover:bg-amber-300">
                  {t('aiWorkspace.overview.manageProviders')}
                </Link>
              </Card>
            )}

            {/* Headline metrics */}
            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
              <StatTile label={t('aiWorkspace.overview.conversations')} value={formatNumber(s.conversation_count)} />
              <StatTile label={t('aiWorkspace.overview.messages')} value={formatNumber(s.message_count)} />
              <StatTile label={t('aiWorkspace.overview.totalTokens')} value={formatNumber(s.total_tokens)} />
              <StatTile label={t('aiWorkspace.overview.skillsLoaded')} value={formatNumber(s.skills_loaded)} />
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
              {/* Operations */}
              <Card className="space-y-3">
                <h2 className="text-sm font-semibold text-white">{t('aiWorkspace.overview.operations')}</h2>
                <div className="grid gap-2 sm:grid-cols-2">
                  <Row label={t('aiWorkspace.overview.aiEnabled')} value={s.ai_enabled ? t('aiWorkspace.common.on') : t('aiWorkspace.common.off')} />
                  <Row label={t('aiWorkspace.overview.capabilitiesLoaded')} value={formatNumber(s.capabilities_loaded)} />
                  <Row label={t('aiWorkspace.overview.promptTokens')} value={formatNumber(s.prompt_tokens)} />
                  <Row label={t('aiWorkspace.overview.completionTokens')} value={formatNumber(s.completion_tokens)} />
                  <Row label={t('aiWorkspace.overview.estimatedCost')} value={s.pricing_configured ? <Link to="/ai-workspace/costs" className="text-sky-300 hover:underline">{t('aiWorkspace.nav.costs')}</Link> : t('aiWorkspace.overview.costNotConfigured')} />
                  <Row label={t('aiWorkspace.overview.lastActivity')} value={formatDateTime(s.last_activity_at)} />
                </div>
                <p className="text-xs text-white/40">{t('aiWorkspace.overview.yourRole')}: {res.permissions.role}</p>
              </Card>

              {/* Providers */}
              <Card className="space-y-3">
                <div className="flex items-center justify-between">
                  <h2 className="text-sm font-semibold text-white">{t('aiWorkspace.overview.providers')}</h2>
                  <Link to="/ai-workspace/providers" className="text-xs text-sky-300 hover:underline">{t('aiWorkspace.overview.manageProviders')}</Link>
                </div>
                <div className="grid gap-2 sm:grid-cols-2">
                  <Row label={t('aiWorkspace.overview.defaultProvider')} value={s.default_provider ?? t('aiWorkspace.common.notSet')} />
                  <Row label={t('aiWorkspace.overview.catalogProviders')} value={formatNumber(s.catalog_provider_count)} />
                  <Row label={t('aiWorkspace.overview.executableProviders')} value={formatNumber(s.executable_provider_count)} />
                  <Row label={t('aiWorkspace.overview.enabledProviders')} value={formatNumber(s.enabled_provider_count)} />
                  <Row label={t('aiWorkspace.overview.configuredProviders')} value={formatNumber(s.configured_provider_count)} />
                  <Row label={t('aiWorkspace.overview.templates')} value={formatNumber(s.template_count)} />
                </div>
              </Card>
            </div>

            {/* Recent activity timeline */}
            <Card className="space-y-3">
              <h2 className="text-sm font-semibold text-white">{t('aiWorkspace.overview.recentActivity')}</h2>
              {activity.data && activity.data.items.length > 0 ? (
                <ul className="divide-y divide-white/5">
                  {activity.data.items.slice(0, 8).map((item) => (
                    <li key={item.uuid} className="flex items-center justify-between gap-3 py-2 text-xs">
                      <span className="truncate text-white/80">
                        {item.action}
                        {item.provider ? <span className="ml-1 font-mono text-white/40">· {item.provider}</span> : null}
                      </span>
                      <span className="shrink-0 text-white/40">{formatDateTime(item.occurred_at)}</span>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-xs text-white/40">{t('aiWorkspace.overview.noRecentActivity')}</p>
              )}
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
