import { useCallback, useEffect, useState } from 'react'
import { PageHeader } from '../../components/observe/PageHeader'
import { EmptyState } from '../../components/observe/capacity/EmptyState'
import { useLanguage } from '../../i18n/LanguageContext'
import { useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { AiCopilotDrawer } from '../../components/ai/AiCopilotDrawer'
import { qynvaService } from '../../services/qynvaService'
import type { OperatorCapabilities } from '../../types/qynva'
import { QynvaTabs } from './QynvaTabs'

/**
 * Sprint 25 — QynVA Enterprise AI Operator console. Discovers modules/capabilities/actions across the
 * platform and lets operators reason via the shared Quenyx AI conversation surface. QynVA proposes
 * evidence-based plans — it never executes; the owning module executes after approval.
 */
export default function OperatorConsole() {
  const { t } = useLanguage()
  const { workspaceUuid, hasWorkspace } = useAiWorkspaceUuid()
  const [caps, setCaps] = useState<OperatorCapabilities | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [copilotOpen, setCopilotOpen] = useState(false)

  const load = useCallback(async () => {
    if (!workspaceUuid) return
    try {
      setCaps(await qynvaService.capabilities(workspaceUuid))
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Failed to load operator capabilities')
    }
  }, [workspaceUuid])

  useEffect(() => {
    void load()
  }, [load])

  if (!hasWorkspace) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('qynva.title')} subtitle={t('qynva.subtitle')} />
        <EmptyState title={t('qynva.noWorkspace.title')} description={t('qynva.noWorkspace.description')} />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <PageHeader title={t('qynva.title')} subtitle={t('qynva.subtitle')} />
        <button onClick={() => setCopilotOpen(true)} className="rounded-full border border-amber-400/40 bg-amber-400/10 px-3 py-1.5 text-xs font-semibold text-amber-100 hover:bg-amber-400/20">
          ✨ {t('qynva.askOperator')}
        </button>
      </div>

      <QynvaTabs />

      {notice ? <p className="text-sm text-amber-300">{notice}</p> : null}

      <div className="rounded-lg border border-white/10 bg-[#0f151d] p-4">
        <p className="text-sm text-white/70">{t('qynva.operatorIntro')}</p>
        <p className="mt-2 text-xs text-white/40">{t('qynva.discovered')}: {caps?.module_count ?? 0} · {t('qynva.capabilities')}: {caps?.capabilities.length ?? 0} · {t('qynva.actions')}: {caps?.actions.length ?? 0}</p>
      </div>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {(caps?.modules ?? []).map((m) => (
          <div key={m.module} className="rounded-lg border border-white/10 bg-[#0f151d] p-3">
            <div className="flex items-center justify-between">
              <span className="text-sm font-medium text-white">{m.name}</span>
              <span className="text-[10px] uppercase text-white/40">{m.category}</span>
            </div>
            <div className="mt-2 flex flex-wrap gap-1">
              {m.capabilities.map((c) => (
                <span key={c} className="rounded bg-white/5 px-1.5 py-0.5 text-[10px] text-white/60">{c}</span>
              ))}
            </div>
          </div>
        ))}
      </div>

      <AiCopilotDrawer
        open={copilotOpen}
        onClose={() => setCopilotOpen(false)}
        workspaceUuid={workspaceUuid}
        copilot={(w, message, conversation) => qynvaService.operate(w, message, conversation)}
        title={t('qynva.operatorTitle')}
        introText={t('qynva.operatorDrawerIntro')}
      />
    </div>
  )
}
