import { useLanguage } from '../../i18n/LanguageContext'
import { aiWorkspaceService } from '../../services/aiWorkspaceService'
import { useAiResource, useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { AiView, Card, formatNumber } from '../../components/ai/workspace/shared'

/**
 * AI Cost Tracking — costs are derived from real token counts × configured pricing. When pricing is
 * not configured the UI shows an honest "pricing not configured" state with token totals only, never
 * a fabricated currency amount.
 */
export default function AiCosts() {
  const { t } = useLanguage()
  const { hasWorkspace } = useAiWorkspaceUuid()
  const { data, loading, error, reload } = useAiResource((uuid) => aiWorkspaceService.getCosts(uuid))

  return (
    <AiView hasWorkspace={hasWorkspace} loading={loading} error={error} data={data} onRetry={reload}>
      {(c) => (
        <div className="space-y-4">
          {!c.pricing_configured ? (
            <div className="rounded-lg border border-amber-400/30 bg-amber-400/10 px-4 py-3 text-sm text-amber-100">
              {t('aiWorkspace.costs.notConfigured')}
            </div>
          ) : (
            <Card className="flex items-center justify-between">
              <span className="text-sm text-white/60">{t('aiWorkspace.costs.total')}</span>
              <span className="text-2xl font-semibold text-white">
                {c.total_cost ?? 0} {c.currency}
              </span>
            </Card>
          )}

          <Card className="space-y-3">
            <h3 className="text-sm font-semibold text-white">{t('aiWorkspace.costs.byProvider')}</h3>
            {c.by_provider.length === 0 ? (
              <p className="text-sm text-white/50">{t('aiWorkspace.costs.empty')}</p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-xs uppercase tracking-wide text-white/40">
                      <th className="py-2 text-start">{t('aiWorkspace.costs.provider')}</th>
                      <th className="py-2 text-end">{t('aiWorkspace.usage.totalTokens')}</th>
                      <th className="py-2 text-end">{t('aiWorkspace.costs.cost')}</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-white/5">
                    {c.by_provider.map((row) => (
                      <tr key={row.provider}>
                        <td className="py-2 text-white">{row.provider}</td>
                        <td className="py-2 text-end text-white/70">{formatNumber(row.total_tokens)}</td>
                        <td className="py-2 text-end text-white/70">
                          {row.pricing_configured && row.cost !== null ? `${row.cost} ${row.currency}` : t('aiWorkspace.costs.na')}
                        </td>
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
