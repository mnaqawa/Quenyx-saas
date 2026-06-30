import { useCallback, useEffect, useState } from 'react'
import { PageHeader } from '../../components/observe/PageHeader'
import { EmptyState } from '../../components/observe/capacity/EmptyState'
import { useLanguage } from '../../i18n/LanguageContext'
import { useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { AiCopilotDrawer } from '../../components/ai/AiCopilotDrawer'
import { qynbalanceService } from '../../services/qynbalanceService'
import type { CostOverview } from '../../types/qynbalance'

/**
 * Sprint 25 — QynBalance Enterprise Cost Intelligence. Shows real resource counts and, ONLY where the
 * operator configured real unit rates, monetary estimates. When pricing is unset it clearly states
 * "pricing unavailable" rather than inventing a number. Includes evidence-based recommendations.
 */
export default function CostIntelligence() {
  const { t } = useLanguage()
  const { workspaceUuid, hasWorkspace } = useAiWorkspaceUuid()
  const [data, setData] = useState<CostOverview | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [copilotOpen, setCopilotOpen] = useState(false)

  const load = useCallback(async () => {
    if (!workspaceUuid) return
    try {
      setData(await qynbalanceService.overview(workspaceUuid))
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Failed to load cost overview')
    }
  }, [workspaceUuid])

  useEffect(() => {
    void load()
  }, [load])

  if (!hasWorkspace) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('qynbalance.title')} subtitle={t('qynbalance.subtitle')} />
        <EmptyState title={t('qynbalance.noWorkspace.title')} description={t('qynbalance.noWorkspace.description')} />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <PageHeader title={t('qynbalance.title')} subtitle={t('qynbalance.subtitle')} />
        <button onClick={() => setCopilotOpen(true)} className="rounded-full border border-amber-400/40 bg-amber-400/10 px-3 py-1.5 text-xs font-semibold text-amber-100 hover:bg-amber-400/20">
          ✨ {t('qynbalance.askCost')}
        </button>
      </div>
      {notice ? <p className="text-sm text-amber-300">{notice}</p> : null}

      {!data?.pricing_configured ? (
        <div className="rounded-lg border border-amber-400/30 bg-amber-400/5 p-3 text-xs text-amber-200">
          {t('qynbalance.pricingUnavailable')}
        </div>
      ) : null}

      {data ? (
        <>
          <div className="rounded-lg border border-white/10 bg-[#0f151d] p-4">
            <p className="mb-2 text-sm font-semibold text-white">{t('qynbalance.infrastructure')}</p>
            <table className="w-full text-xs text-white/70">
              <thead>
                <tr className="text-white/40">
                  <th className="text-start font-medium">{t('qynbalance.resource')}</th>
                  <th className="text-start font-medium">{t('qynbalance.count')}</th>
                  <th className="text-start font-medium">{t('qynbalance.monthlyCost')}</th>
                </tr>
              </thead>
              <tbody>
                {data.infrastructure.lines.map((line) => (
                  <tr key={line.resource} className="border-t border-white/5">
                    <td className="py-1">{line.resource}</td>
                    <td className="py-1">{line.count}</td>
                    <td className="py-1">
                      {line.pricing_available ? `${line.monthly_cost} ${line.currency}` : <span className="text-amber-300">{t('qynbalance.unavailable')}</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <p className="mt-2 text-xs text-white/50">
              {t('qynbalance.estimatedMonthly')}: {data.infrastructure.estimated_monthly_total !== null ? `${data.infrastructure.estimated_monthly_total} ${data.currency}` : t('qynbalance.unavailable')}
            </p>
          </div>

          <div className="rounded-lg border border-white/10 bg-[#0f151d] p-4">
            <p className="mb-2 text-sm font-semibold text-white">{t('qynbalance.recommendations')}</p>
            {data.recommendations.length === 0 ? (
              <p className="text-xs text-white/40">{t('qynbalance.noRecommendations')}</p>
            ) : (
              <ul className="space-y-1">
                {data.recommendations.map((r) => (
                  <li key={r.key} className="text-xs text-white/70">
                    <span className="text-sky-300">[{r.severity}]</span> {r.recommendation} <span className="text-white/40">— {r.evidence}</span>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </>
      ) : (
        <p className="text-sm text-white/40">{t('qynbalance.loading')}</p>
      )}

      <AiCopilotDrawer
        open={copilotOpen}
        onClose={() => setCopilotOpen(false)}
        workspaceUuid={workspaceUuid}
        copilot={(w, message, conversation) => qynbalanceService.copilot(w, message, conversation)}
        title={t('qynbalance.copilotTitle')}
        introText={t('qynbalance.copilotIntro')}
      />
    </div>
  )
}
