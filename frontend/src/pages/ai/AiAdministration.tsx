import { Link } from 'react-router-dom'
import { useLanguage } from '../../i18n/LanguageContext'
import { aiWorkspaceService } from '../../services/aiWorkspaceService'
import { useAiResource, useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { AiView, Card, StatTile } from '../../components/ai/workspace/shared'
import { formatNumber } from '../../components/ai/workspace/format'

/**
 * Workspace AI Administration — landing for owners/admins. Surfaces governance status and links to
 * the provider settings and permission matrix. Honestly reflects RBAC: non-admins see a notice via
 * the summary's permissions (administration links still require admin on the backend).
 */
export default function AiAdministration() {
  const { t } = useLanguage()
  const { hasWorkspace } = useAiWorkspaceUuid()
  const { data, loading, error, reload } = useAiResource((uuid) => aiWorkspaceService.getSummary(uuid))

  return (
    <AiView hasWorkspace={hasWorkspace} loading={loading} error={error} data={data} onRetry={reload}>
      {(res) => {
        const canAdmin = res.permissions.can_administer
        return (
          <div className="space-y-6">
            {!canAdmin ? (
              <div className="rounded-lg border border-amber-400/30 bg-amber-400/10 px-4 py-3 text-sm text-amber-100">
                {t('aiWorkspace.administration.notAdmin')}
              </div>
            ) : null}

            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
              <StatTile label={t('aiWorkspace.administration.aiEnabled')} value={res.summary.ai_enabled ? t('aiWorkspace.common.on') : t('aiWorkspace.common.off')} />
              <StatTile label={t('aiWorkspace.administration.providers')} value={formatNumber(res.summary.configured_provider_count)} />
              <StatTile label={t('aiWorkspace.administration.templates')} value={formatNumber(res.summary.template_count)} />
              <StatTile label={t('aiWorkspace.administration.yourRole')} value={res.permissions.role} />
            </div>

            <div className="grid gap-4 md:grid-cols-2">
              <Link to="/ai-workspace/providers">
                <Card className="transition hover:border-sky-400/40">
                  <h3 className="text-sm font-semibold text-white">{t('aiWorkspace.nav.providers')}</h3>
                  <p className="mt-1 text-xs text-white/50">{t('aiWorkspace.administration.providersHint')}</p>
                </Card>
              </Link>
              <Link to="/ai-workspace/permissions">
                <Card className="transition hover:border-sky-400/40">
                  <h3 className="text-sm font-semibold text-white">{t('aiWorkspace.nav.permissions')}</h3>
                  <p className="mt-1 text-xs text-white/50">{t('aiWorkspace.administration.permissionsHint')}</p>
                </Card>
              </Link>
            </div>
          </div>
        )
      }}
    </AiView>
  )
}
