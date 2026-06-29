import { useLanguage } from '../../i18n/LanguageContext'
import { aiWorkspaceService } from '../../services/aiWorkspaceService'
import { useAiResource, useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { AiView, Card, StatTile } from '../../components/ai/workspace/shared'
import { formatNumber } from '../../components/ai/workspace/format'

export default function AiUsage() {
  const { t } = useLanguage()
  const { hasWorkspace } = useAiWorkspaceUuid()
  const { data, loading, error, reload } = useAiResource((uuid) => aiWorkspaceService.getUsage(uuid))

  return (
    <AiView hasWorkspace={hasWorkspace} loading={loading} error={error} data={data} onRetry={reload}>
      {(u) => (
        <div className="space-y-6">
          <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <StatTile label={t('aiWorkspace.usage.conversations')} value={formatNumber(u.totals.conversation_count)} />
            <StatTile label={t('aiWorkspace.usage.promptTokens')} value={formatNumber(u.totals.prompt_tokens)} />
            <StatTile label={t('aiWorkspace.usage.completionTokens')} value={formatNumber(u.totals.completion_tokens)} />
            <StatTile label={t('aiWorkspace.usage.totalTokens')} value={formatNumber(u.totals.total_tokens)} />
          </div>

          <Card className="space-y-3">
            <h3 className="text-sm font-semibold text-white">{t('aiWorkspace.usage.byProvider')}</h3>
            {u.by_provider.length === 0 ? (
              <p className="text-sm text-white/50">{t('aiWorkspace.usage.empty')}</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-start text-xs uppercase tracking-wide text-white/40">
                      <th className="py-2 text-start">{t('aiWorkspace.usage.provider')}</th>
                      <th className="py-2 text-end">{t('aiWorkspace.usage.conversations')}</th>
                      <th className="py-2 text-end">{t('aiWorkspace.usage.totalTokens')}</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-white/5">
                    {u.by_provider.map((row) => (
                      <tr key={row.provider}>
                        <td className="py-2 text-white">{row.provider}</td>
                        <td className="py-2 text-end text-white/70">{formatNumber(row.conversation_count)}</td>
                        <td className="py-2 text-end text-white/70">{formatNumber(row.total_tokens)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>
        </div>
      )}
    </AiView>
  )
}
