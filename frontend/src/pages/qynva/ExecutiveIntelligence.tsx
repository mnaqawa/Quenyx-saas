import { useCallback, useEffect, useState } from 'react'
import { PageHeader } from '../../components/observe/PageHeader'
import { EmptyState } from '../../components/observe/capacity/EmptyState'
import { useLanguage } from '../../i18n/LanguageContext'
import { useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { qynvaService } from '../../services/qynvaService'
import type { ExecutiveDashboard, HealthBlock } from '../../types/qynva'
import type { AiNarrative } from '../../types/automation'
import { QynvaTabs } from './QynvaTabs'

function bandColor(status?: string): string {
  if (status === 'healthy') return 'text-emerald-300'
  if (status === 'degraded') return 'text-amber-300'
  if (status === 'at_risk') return 'text-rose-300'
  return 'text-white/60'
}

function HealthCard({ title, block }: { title: string; block: HealthBlock }) {
  const available = block.available !== false
  return (
    <div className="rounded-lg border border-white/10 bg-[#0f151d] p-4">
      <p className="text-xs uppercase tracking-wide text-white/40">{title}</p>
      {available ? (
        <>
          <p className={`mt-1 text-2xl font-semibold ${bandColor(block.status)}`}>
            {typeof block.score === 'number' ? block.score : '—'}
            {typeof block.score === 'number' ? <span className="text-sm text-white/40"> /100</span> : null}
          </p>
          <p className={`text-xs ${bandColor(block.status)}`}>{block.status ?? ''}</p>
        </>
      ) : (
        <p className="mt-2 text-xs text-white/40">{block.reason ?? 'Insufficient data'}</p>
      )}
    </div>
  )
}

/**
 * Sprint 25 — Executive Intelligence dashboard. Evidence-based health, KPIs, top risks/recommendations,
 * and an AI-narrated executive summary (over the same deterministic evidence — never fabricated).
 */
export default function ExecutiveIntelligence() {
  const { t } = useLanguage()
  const { workspaceUuid, hasWorkspace } = useAiWorkspaceUuid()
  const [data, setData] = useState<ExecutiveDashboard | null>(null)
  const [summary, setSummary] = useState<AiNarrative | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    if (!workspaceUuid) return
    try {
      setData(await qynvaService.executive(workspaceUuid))
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Failed to load executive dashboard')
    }
  }, [workspaceUuid])

  useEffect(() => {
    void load()
  }, [load])

  const generate = async () => {
    if (!workspaceUuid) return
    setBusy(true)
    try {
      const res = await qynvaService.executiveSummary(workspaceUuid)
      setSummary(res.executive_summary)
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Failed to generate summary')
    } finally {
      setBusy(false)
    }
  }

  if (!hasWorkspace) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('qynva.executiveTitle')} subtitle={t('qynva.executiveSubtitle')} />
        <EmptyState title={t('qynva.noWorkspace.title')} description={t('qynva.noWorkspace.description')} />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader title={t('qynva.executiveTitle')} subtitle={t('qynva.executiveSubtitle')} />
      <QynvaTabs />
      {notice ? <p className="text-sm text-amber-300">{notice}</p> : null}

      {data ? (
        <>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <HealthCard title={t('qynva.health.operational')} block={data.operational_health} />
            <HealthCard title={t('qynva.health.infrastructure')} block={data.infrastructure_health} />
            <HealthCard title={t('qynva.health.compliance')} block={data.compliance_health} />
          </div>

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div className="rounded-lg border border-white/10 bg-[#0f151d] p-4">
              <p className="mb-2 text-sm font-semibold text-white">{t('qynva.topRisks')}</p>
              {data.top_risks.length === 0 ? (
                <p className="text-xs text-white/40">{t('qynva.noRisks')}</p>
              ) : (
                <ul className="space-y-1">
                  {data.top_risks.map((r, i) => (
                    <li key={i} className="text-xs text-white/70">
                      <span className="text-rose-300">[{r.severity}]</span> {r.title} <span className="text-white/40">· {r.type}</span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
            <div className="rounded-lg border border-white/10 bg-[#0f151d] p-4">
              <p className="mb-2 text-sm font-semibold text-white">{t('qynva.topRecommendations')}</p>
              {data.top_recommendations.length === 0 ? (
                <p className="text-xs text-white/40">{t('qynva.noRecommendations')}</p>
              ) : (
                <ul className="space-y-1">
                  {data.top_recommendations.map((r) => (
                    <li key={r.key} className="text-xs text-white/70">{r.recommendation} <span className="text-white/40">— {r.evidence}</span></li>
                  ))}
                </ul>
              )}
            </div>
          </div>

          <div className="rounded-lg border border-white/10 bg-[#0f151d] p-4">
            <div className="flex items-center justify-between">
              <p className="text-sm font-semibold text-white">{t('qynva.executiveSummary')}</p>
              <button onClick={generate} disabled={busy} className="rounded-full border border-amber-400/40 bg-amber-400/10 px-3 py-1.5 text-xs font-semibold text-amber-100 hover:bg-amber-400/20 disabled:opacity-50">
                {busy ? t('qynva.generating') : `✨ ${t('qynva.generateSummary')}`}
              </button>
            </div>
            {summary ? (
              <div className="mt-3">
                {summary.ai_enabled === false ? <p className="mb-1 text-[10px] uppercase text-amber-300">{t('qynva.mockNotice')}</p> : null}
                <p className="whitespace-pre-wrap text-sm text-white/80">{summary.content ?? summary.error ?? ''}</p>
              </div>
            ) : (
              <p className="mt-2 text-xs text-white/40">{t('qynva.summaryHint')}</p>
            )}
          </div>
        </>
      ) : (
        <p className="text-sm text-white/40">{t('qynva.loading')}</p>
      )}
    </div>
  )
}
