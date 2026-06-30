import { useCallback, useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { PageHeader } from '../../components/observe/PageHeader'
import { EmptyState } from '../../components/observe/capacity/EmptyState'
import { useLanguage } from '../../i18n/LanguageContext'
import { useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { AiCopilotDrawer } from '../../components/ai/AiCopilotDrawer'
import { incidentService } from '../../services/incidentService'
import type { AiNarrative } from '../../types/automation'
import type { IncidentSummary, IncidentWorkspaceData } from '../../types/incident'

const SEVERITY_CLASS: Record<string, string> = {
  critical: 'text-rose-300',
  high: 'text-amber-300',
  medium: 'text-sky-300',
  low: 'text-white/50',
}

export default function IncidentWorkspace() {
  const { t } = useLanguage()
  const { workspaceUuid, hasWorkspace } = useAiWorkspaceUuid()

  const [incidents, setIncidents] = useState<IncidentSummary[]>([])
  const [selected, setSelected] = useState<IncidentWorkspaceData | null>(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)
  const [copilotOpen, setCopilotOpen] = useState(false)
  const [ai, setAi] = useState<{ kind: string; narrative: AiNarrative } | null>(null)
  const [newTitle, setNewTitle] = useState('')

  const loadList = useCallback(async () => {
    if (!workspaceUuid) return
    setLoading(true)
    try {
      setIncidents((await incidentService.list(workspaceUuid)).incidents)
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Failed to load incidents')
    } finally {
      setLoading(false)
    }
  }, [workspaceUuid])

  useEffect(() => {
    void loadList()
  }, [loadList])

  const openIncident = useCallback(async (uuid: string) => {
    if (!workspaceUuid) return
    setAi(null)
    setNotice(null)
    try {
      setSelected(await incidentService.get(workspaceUuid, uuid))
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Failed to open incident')
    }
  }, [workspaceUuid])

  const createIncident = useCallback(async () => {
    if (!workspaceUuid || !newTitle.trim()) return
    setBusy(true)
    try {
      const created = await incidentService.create(workspaceUuid, { title: newTitle.trim(), severity: 'medium' })
      setNewTitle('')
      await loadList()
      await openIncident(created.uuid)
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Failed to create incident')
    } finally {
      setBusy(false)
    }
  }, [workspaceUuid, newTitle, loadList, openIncident])

  const runAi = useCallback(async (kind: 'recommend' | 'postmortem') => {
    if (!workspaceUuid || !selected) return
    setBusy(true)
    setAi(null)
    try {
      if (kind === 'recommend') {
        const res = await incidentService.recommend(workspaceUuid, selected.incident.uuid)
        setAi({ kind, narrative: res.recommendations })
      } else {
        const res = await incidentService.postmortem(workspaceUuid, selected.incident.uuid)
        setAi({ kind, narrative: res.postmortem_draft })
      }
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'AI action failed')
    } finally {
      setBusy(false)
    }
  }, [workspaceUuid, selected])

  if (!hasWorkspace) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('incident.title')} subtitle={t('incident.subtitle')} />
        <EmptyState title={t('incident.noWorkspace.title')} description={t('incident.noWorkspace.description')} />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader title={t('incident.title')} subtitle={t('incident.subtitle')} />

      {notice ? <p className="text-sm text-amber-300">{notice}</p> : null}

      <div className="flex gap-2">
        <input
          value={newTitle}
          onChange={(e) => setNewTitle(e.target.value)}
          placeholder={t('incident.newPlaceholder')}
          className="flex-1 rounded-lg border border-white/15 bg-[#0f151d] px-3 py-2 text-sm text-white placeholder:text-white/30 focus:border-sky-500 focus:outline-none"
        />
        <button disabled={busy || !newTitle.trim()} onClick={createIncident} className="rounded-lg bg-sky-500 px-4 py-2 text-xs font-semibold text-white hover:bg-sky-400 disabled:opacity-40">
          {t('incident.create')}
        </button>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div className="space-y-2">
          <h3 className="text-sm font-semibold text-white">{t('incident.list')}</h3>
          {loading ? <p className="text-xs text-white/40">{t('incident.loading')}</p> : null}
          {!loading && incidents.length === 0 ? <p className="text-xs text-white/40">{t('incident.empty')}</p> : null}
          {incidents.map((i) => (
            <button
              key={i.uuid}
              onClick={() => openIncident(i.uuid)}
              className={`block w-full rounded-lg border p-3 text-left ${selected?.incident.uuid === i.uuid ? 'border-sky-400/50 bg-sky-400/10' : 'border-white/10 bg-[#0f151d] hover:border-white/20'}`}
            >
              <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-white">{i.title}</span>
                <span className={`text-[10px] uppercase ${SEVERITY_CLASS[i.severity] ?? 'text-white/50'}`}>{i.severity}</span>
              </div>
              <div className="text-xs text-white/40">{i.status}</div>
            </button>
          ))}
        </div>

        <div className="lg:col-span-2">
          {!selected ? (
            <EmptyState title={t('incident.selectTitle')} description={t('incident.selectDescription')} />
          ) : (
            <div className="space-y-4">
              <div className="flex items-center justify-between">
                <div>
                  <h2 className="text-base font-semibold text-white">{selected.incident.title}</h2>
                  <p className="text-xs text-white/50">{selected.incident.status} · {selected.incident.severity}</p>
                </div>
                <div className="flex gap-2">
                  <button onClick={() => setCopilotOpen(true)} className="rounded-full border border-amber-400/40 bg-amber-400/10 px-3 py-1.5 text-xs font-semibold text-amber-100 hover:bg-amber-400/20">✨ {t('incident.copilot')}</button>
                  <button disabled={busy} onClick={() => runAi('recommend')} className="rounded-full border border-sky-400/40 px-3 py-1.5 text-xs text-sky-200 hover:bg-sky-400/10">{t('incident.recommend')}</button>
                  <button disabled={busy} onClick={() => runAi('postmortem')} className="rounded-full border border-white/15 px-3 py-1.5 text-xs text-white/70 hover:bg-white/10">{t('incident.postmortem')}</button>
                </div>
              </div>

              {ai ? (
                <div className="rounded-lg border border-white/10 bg-[#0b0f14] p-3">
                  <p className="text-[10px] uppercase tracking-wide text-white/40">{ai.kind}</p>
                  <p className="mt-1 whitespace-pre-wrap text-sm text-white/85">{ai.narrative.content ?? ai.narrative.error ?? t('incident.noAnswer')}</p>
                  {ai.narrative.ai_enabled === false ? <p className="mt-2 text-[10px] uppercase tracking-wide text-amber-300/80">{t('aiCopilot.mockNotice')}</p> : null}
                </div>
              ) : null}

              <Section title={`${t('incident.crossModule')} (${selected.cross_module.module_count})`}>
                {selected.cross_module.modules.map((m) => (
                  <div key={m.module} className="rounded border border-white/10 bg-[#0f151d] p-2 text-xs">
                    <span className="font-semibold text-white">{m.name}</span>
                    <span className="text-white/40"> · {m.category} · {m.capabilities.length} {t('incident.capabilities')}</span>
                  </div>
                ))}
              </Section>

              <Section title={t('incident.timeline')}>
                {selected.timeline.length === 0 ? <p className="text-xs text-white/40">{t('incident.noTimeline')}</p> : selected.timeline.map((e, idx) => (
                  <div key={idx} className="border-l border-white/10 pl-3 text-xs">
                    <span className="text-white/40">{e.at}</span> · <span className="text-white/70">{e.type}</span>: <span className="text-white/85">{e.description}</span>
                  </div>
                ))}
              </Section>

              <Section title={t('incident.automation')}>
                {selected.automation.length === 0 ? <p className="text-xs text-white/40">{t('incident.noAutomation')}</p> : selected.automation.map((e) => (
                  <div key={e.uuid} className="text-xs text-white/70">{e.adapter_key} · {e.action_key ?? '—'} · {e.status}</div>
                ))}
              </Section>

              <Section title={t('incident.knowledge')}>
                <p className="text-xs text-white/40">{selected.knowledge.note}</p>
              </Section>
            </div>
          )}
        </div>
      </div>

      <AiCopilotDrawer
        open={copilotOpen}
        onClose={() => setCopilotOpen(false)}
        workspaceUuid={workspaceUuid}
        copilot={(w, message, conversation) => incidentService.copilot(w, selected?.incident.uuid ?? '', message, conversation)}
        title={t('incident.copilotTitle')}
        introText={t('incident.copilotIntro')}
      />
    </div>
  )
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="space-y-2">
      <h3 className="text-sm font-semibold text-white">{title}</h3>
      <div className="space-y-1">{children}</div>
    </section>
  )
}
