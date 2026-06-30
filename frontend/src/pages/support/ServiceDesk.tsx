import { useCallback, useEffect, useState } from 'react'
import { PageHeader } from '../../components/observe/PageHeader'
import { EmptyState } from '../../components/observe/capacity/EmptyState'
import { useLanguage } from '../../i18n/LanguageContext'
import { useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { AiCopilotDrawer } from '../../components/ai/AiCopilotDrawer'
import { CollaborationPanel } from '../../components/collaboration/CollaborationPanel'
import { supportService } from '../../services/supportService'
import type { TicketAnalyzeResult, TicketDetail, TicketSummary } from '../../types/support'

const PRIORITY_CLASS: Record<string, string> = {
  critical: 'text-rose-300',
  high: 'text-amber-300',
  medium: 'text-sky-300',
  low: 'text-white/50',
}

export default function ServiceDesk() {
  const { t } = useLanguage()
  const { workspaceUuid, hasWorkspace } = useAiWorkspaceUuid()

  const [tickets, setTickets] = useState<TicketSummary[]>([])
  const [selected, setSelected] = useState<TicketDetail | null>(null)
  const [analysis, setAnalysis] = useState<TicketAnalyzeResult | null>(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)
  const [copilotOpen, setCopilotOpen] = useState(false)
  const [newSubject, setNewSubject] = useState('')

  const loadList = useCallback(async () => {
    if (!workspaceUuid) return
    setLoading(true)
    try {
      setTickets((await supportService.list(workspaceUuid)).tickets)
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Failed to load tickets')
    } finally {
      setLoading(false)
    }
  }, [workspaceUuid])

  useEffect(() => {
    void loadList()
  }, [loadList])

  const openTicket = useCallback(async (uuid: string) => {
    if (!workspaceUuid) return
    setAnalysis(null)
    try {
      setSelected(await supportService.get(workspaceUuid, uuid))
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Failed to open ticket')
    }
  }, [workspaceUuid])

  const createTicket = useCallback(async () => {
    if (!workspaceUuid || !newSubject.trim()) return
    setBusy(true)
    try {
      const created = await supportService.create(workspaceUuid, { subject: newSubject.trim(), priority: 'medium' })
      setNewSubject('')
      await loadList()
      await openTicket(created.uuid)
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Failed to create ticket')
    } finally {
      setBusy(false)
    }
  }, [workspaceUuid, newSubject, loadList, openTicket])

  const analyze = useCallback(async () => {
    if (!workspaceUuid || !selected) return
    setBusy(true)
    setAnalysis(null)
    try {
      setAnalysis(await supportService.analyze(workspaceUuid, selected.uuid))
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'AI triage failed')
    } finally {
      setBusy(false)
    }
  }, [workspaceUuid, selected])

  if (!hasWorkspace) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('servicedesk.title')} subtitle={t('servicedesk.subtitle')} />
        <EmptyState title={t('servicedesk.noWorkspace.title')} description={t('servicedesk.noWorkspace.description')} />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader title={t('servicedesk.title')} subtitle={t('servicedesk.subtitle')} />

      {notice ? <p className="text-sm text-amber-300">{notice}</p> : null}

      <div className="flex gap-2">
        <input value={newSubject} onChange={(e) => setNewSubject(e.target.value)} placeholder={t('servicedesk.newPlaceholder')} className="flex-1 rounded-lg border border-white/15 bg-[#0f151d] px-3 py-2 text-sm text-white placeholder:text-white/30 focus:border-sky-500 focus:outline-none" />
        <button disabled={busy || !newSubject.trim()} onClick={createTicket} className="rounded-lg bg-sky-500 px-4 py-2 text-xs font-semibold text-white hover:bg-sky-400 disabled:opacity-40">{t('servicedesk.create')}</button>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div className="space-y-2">
          <h3 className="text-sm font-semibold text-white">{t('servicedesk.list')}</h3>
          {loading ? <p className="text-xs text-white/40">{t('servicedesk.loading')}</p> : null}
          {!loading && tickets.length === 0 ? <p className="text-xs text-white/40">{t('servicedesk.empty')}</p> : null}
          {tickets.map((tk) => (
            <button key={tk.uuid} onClick={() => openTicket(tk.uuid)} className={`block w-full rounded-lg border p-3 text-left ${selected?.uuid === tk.uuid ? 'border-sky-400/50 bg-sky-400/10' : 'border-white/10 bg-[#0f151d] hover:border-white/20'}`}>
              <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-white">{tk.subject}</span>
                <span className={`text-[10px] uppercase ${PRIORITY_CLASS[tk.priority] ?? 'text-white/50'}`}>{tk.priority}</span>
              </div>
              <div className="text-xs text-white/40">{tk.reference} · {tk.status}</div>
            </button>
          ))}
        </div>

        <div className="lg:col-span-2">
          {!selected ? (
            <EmptyState title={t('servicedesk.selectTitle')} description={t('servicedesk.selectDescription')} />
          ) : (
            <div className="space-y-4">
              <div className="flex items-center justify-between">
                <div>
                  <h2 className="text-base font-semibold text-white">{selected.subject}</h2>
                  <p className="text-xs text-white/50">{selected.reference} · {selected.status} · {selected.priority}</p>
                </div>
                <div className="flex gap-2">
                  <button onClick={() => setCopilotOpen(true)} className="rounded-full border border-amber-400/40 bg-amber-400/10 px-3 py-1.5 text-xs font-semibold text-amber-100 hover:bg-amber-400/20">✨ {t('servicedesk.copilot')}</button>
                  <button disabled={busy} onClick={analyze} className="rounded-full border border-sky-400/40 px-3 py-1.5 text-xs text-sky-200 hover:bg-sky-400/10">{t('servicedesk.analyze')}</button>
                </div>
              </div>

              {selected.description ? <p className="text-sm text-white/70">{selected.description}</p> : null}

              {analysis ? (
                <div className="space-y-2 rounded-lg border border-white/10 bg-[#0b0f14] p-3">
                  <p className="text-[10px] uppercase tracking-wide text-white/40">{t('servicedesk.suggestions')}</p>
                  <div className="grid grid-cols-2 gap-2 text-xs text-white/80 sm:grid-cols-4">
                    <div><span className="text-white/40">{t('servicedesk.category')}:</span> {analysis.suggestions.category}</div>
                    <div><span className="text-white/40">{t('servicedesk.priority')}:</span> {analysis.suggestions.priority}</div>
                    <div><span className="text-white/40">{t('servicedesk.impact')}:</span> {analysis.suggestions.impact}</div>
                    <div><span className="text-white/40">{t('servicedesk.sla')}:</span> {analysis.suggestions.suggested_sla.hours}h</div>
                  </div>
                  <div className="text-xs text-white/70">
                    <span className="text-white/40">{t('servicedesk.assignee')}:</span>{' '}
                    {analysis.suggestions.suggested_assignee.available
                      ? `${analysis.suggestions.suggested_assignee.user?.name ?? '—'} (${analysis.suggestions.suggested_assignee.resolved_in_category})`
                      : t('servicedesk.noAssignee')}
                  </div>
                  <p className="whitespace-pre-wrap text-sm text-white/85">{analysis.ai_rationale.content ?? analysis.ai_rationale.error ?? t('servicedesk.noAnswer')}</p>
                  {analysis.ai_rationale.ai_enabled === false ? <p className="text-[10px] uppercase tracking-wide text-amber-300/80">{t('aiCopilot.mockNotice')}</p> : null}
                  <p className="text-xs text-white/40">{analysis.note}</p>
                </div>
              ) : null}

              <CollaborationPanel workspaceUuid={workspaceUuid} entityType="ticket" entityUuid={selected.uuid} />
            </div>
          )}
        </div>
      </div>

      <AiCopilotDrawer
        open={copilotOpen}
        onClose={() => setCopilotOpen(false)}
        workspaceUuid={workspaceUuid}
        copilot={(w, message, conversation) => supportService.copilot(w, selected?.uuid ?? '', message, conversation)}
        title={t('servicedesk.copilotTitle')}
        introText={t('servicedesk.copilotIntro')}
      />
    </div>
  )
}
