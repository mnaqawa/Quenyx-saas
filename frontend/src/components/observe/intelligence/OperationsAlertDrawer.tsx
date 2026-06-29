import { useCallback, useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { useLanguage } from '../../../i18n/LanguageContext'
import { operationsIntelligenceService } from '../../../services/operationsIntelligenceService'
import type { OpsAlertExplanation, OpsAlertSummary, OpsIncidentTimeline } from '../../../types/operationsIntelligence'

interface OperationsAlertDrawerProps {
  open: boolean
  onClose: () => void
  workspaceUuid: string | null
  alert: OpsAlertSummary | null
}

/**
 * Sprint 21 — Alert Intelligence drawer. Surfaces the structured, evidence-grounded explanation
 * (impact, most-likely causes, evidence used, suggested actions, confidence) plus the deterministic
 * incident timeline. All numbers are real; the AI narrative is supplementary.
 */
export function OperationsAlertDrawer({ open, onClose, workspaceUuid, alert }: OperationsAlertDrawerProps) {
  const { t } = useLanguage()
  const [tab, setTab] = useState<'explain' | 'timeline'>('explain')
  const [explanation, setExplanation] = useState<OpsAlertExplanation | null>(null)
  const [timeline, setTimeline] = useState<OpsIncidentTimeline | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(
    async (which: 'explain' | 'timeline') => {
      if (!workspaceUuid || !alert) return
      setLoading(true)
      setError(null)
      try {
        if (which === 'explain') {
          setExplanation(await operationsIntelligenceService.explainAlert(workspaceUuid, alert.uuid))
        } else {
          setTimeline(await operationsIntelligenceService.incidentTimeline(workspaceUuid, alert.uuid))
        }
      } catch (err: unknown) {
        setError(err instanceof Error ? err.message : t('opsIntel.copilot.error'))
      } finally {
        setLoading(false)
      }
    },
    [workspaceUuid, alert, t]
  )

  useEffect(() => {
    if (open && alert) {
      setTab('explain')
      setExplanation(null)
      setTimeline(null)
      void load('explain')
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, alert?.uuid])

  if (!open || !alert) return null

  return (
    <div className="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true">
      <div className="absolute inset-0 bg-black/60" onClick={onClose} />
      <div data-drawer-panel className="relative flex h-full w-full max-w-lg flex-col border-l border-white/10 bg-[#0b0f14] shadow-2xl">
        <div className="flex items-center justify-between border-b border-white/10 px-5 py-4">
          <div className="min-w-0">
            <div className="flex items-center gap-2">
              <span className="text-amber-300">✨</span>
              <h2 className="truncate text-sm font-semibold text-white">{alert.title}</h2>
            </div>
            <p className="mt-0.5 text-xs text-white/50">
              {(alert.host ?? '—')} · {alert.severity} · {alert.status}
            </p>
          </div>
          <button onClick={onClose} className="rounded-full p-1 text-white/50 hover:bg-white/10 hover:text-white" aria-label={t('common.close')}>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M18 6L6 18M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div className="flex gap-1 border-b border-white/10 px-3">
          {(['explain', 'timeline'] as const).map((id) => (
            <button
              key={id}
              onClick={() => {
                setTab(id)
                if (id === 'timeline' && !timeline) void load('timeline')
              }}
              className={`px-3 py-2 text-xs font-medium transition ${tab === id ? 'border-b-2 border-sky-500 text-white' : 'text-white/60 hover:text-white'}`}
            >
              {id === 'explain' ? t('opsIntel.alert.explainTab') : t('opsIntel.alert.timelineTab')}
            </button>
          ))}
        </div>

        <div className="flex-1 space-y-4 overflow-y-auto px-5 py-4 text-sm text-white/90">
          {loading ? <p className="text-xs text-white/50">{t('opsIntel.copilot.thinking')}</p> : null}
          {error ? <p className="text-xs text-rose-300">{error}</p> : null}

          {tab === 'explain' && explanation && !loading ? (
            <>
              <Section title={t('opsIntel.alert.impact')}>
                <p className="text-white/80">{explanation.operational_impact.summary}</p>
              </Section>

              {explanation.most_likely_causes.length > 0 ? (
                <Section title={t('opsIntel.alert.causes')}>
                  <div className="flex flex-wrap gap-2">
                    {explanation.most_likely_causes.map((c, i) => (
                      <span key={i} className="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs">
                        {c.layer}{c.observed_value !== null ? ` · ${c.observed_value}%` : ''} · {c.state}
                      </span>
                    ))}
                  </div>
                </Section>
              ) : null}

              {explanation.suggested_actions.length > 0 ? (
                <Section title={t('opsIntel.alert.actions')}>
                  <ul className="list-disc space-y-1 pl-5 text-white/80">
                    {explanation.suggested_actions.map((a, i) => (
                      <li key={i}>{a}</li>
                    ))}
                  </ul>
                </Section>
              ) : null}

              {explanation.confidence !== null ? (
                <Section title={t('opsIntel.alert.confidence')}>
                  <p className="text-white/80">{Math.round(explanation.confidence * 100)}%</p>
                </Section>
              ) : null}

              {explanation.ai_explanation?.content ? (
                <Section title={t('opsIntel.alert.aiExplanation')}>
                  <p className="whitespace-pre-wrap text-white/80">{explanation.ai_explanation.content}</p>
                  {explanation.ai_explanation.ai_enabled === false ? (
                    <p className="mt-2 text-[10px] uppercase tracking-wide text-amber-300/80">{t('opsIntel.copilot.mockNotice')}</p>
                  ) : null}
                </Section>
              ) : null}

              {explanation.related_alerts.length > 0 ? (
                <Section title={t('opsIntel.alert.related')}>
                  <ul className="space-y-1 text-xs text-white/70">
                    {explanation.related_alerts.slice(0, 8).map((r) => (
                      <li key={r.uuid}>· {r.title} ({r.severity})</li>
                    ))}
                  </ul>
                </Section>
              ) : null}
            </>
          ) : null}

          {tab === 'timeline' && timeline && !loading ? (
            timeline.entries.length === 0 ? (
              <p className="text-xs text-white/50">{t('opsIntel.alert.noTimeline')}</p>
            ) : (
              <ol className="space-y-3">
                {timeline.entries.map((e, i) => (
                  <li key={i} className="flex gap-3">
                    <span className="mt-0.5 font-mono text-xs text-white/40">{new Date(e.at).toLocaleTimeString()}</span>
                    <span className="text-xs text-white/80">{e.description}</span>
                  </li>
                ))}
              </ol>
            )
          ) : null}
        </div>
      </div>
    </div>
  )
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div>
      <h3 className="mb-1 text-xs font-semibold uppercase tracking-wide text-white/50">{title}</h3>
      {children}
    </div>
  )
}
