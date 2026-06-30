import { useCallback, useEffect, useState } from 'react'
import { PageHeader } from '../../components/observe/PageHeader'
import { EmptyState } from '../../components/observe/capacity/EmptyState'
import { useLanguage } from '../../i18n/LanguageContext'
import { useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { AiCopilotDrawer } from '../../components/ai/AiCopilotDrawer'
import { knowledgeService } from '../../services/knowledgeService'
import type {
  DraftKind,
  DraftResult,
  KnowledgeDocumentDetail,
  KnowledgeDocumentSummary,
  KnowledgeOverview,
} from '../../types/knowledge'
import type { AiNarrative } from '../../types/automation'

type Tab = 'documents' | 'sources' | 'assistant' | 'graph'

const DRAFT_KINDS: DraftKind[] = ['kb', 'incident_summary', 'executive_summary', 'technical_summary', 'runbook']

export default function KnowledgeCenter() {
  const { t } = useLanguage()
  const { workspaceUuid, hasWorkspace } = useAiWorkspaceUuid()

  const [tab, setTab] = useState<Tab>('documents')
  const [overview, setOverview] = useState<KnowledgeOverview | null>(null)
  const [documents, setDocuments] = useState<KnowledgeDocumentSummary[]>([])
  const [selected, setSelected] = useState<KnowledgeDocumentDetail | null>(null)
  const [docAi, setDocAi] = useState<AiNarrative | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [copilotOpen, setCopilotOpen] = useState(false)

  const [newTitle, setNewTitle] = useState('')
  const [newBody, setNewBody] = useState('')

  const [draftKind, setDraftKind] = useState<DraftKind>('kb')
  const [draftTopic, setDraftTopic] = useState('')
  const [draft, setDraft] = useState<DraftResult | null>(null)

  const load = useCallback(async () => {
    if (!workspaceUuid) return
    try {
      const [ov, docs] = await Promise.all([
        knowledgeService.getOverview(workspaceUuid),
        knowledgeService.listDocuments(workspaceUuid),
      ])
      setOverview(ov)
      setDocuments(docs.documents)
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Failed to load knowledge center')
    }
  }, [workspaceUuid])

  useEffect(() => {
    void load()
  }, [load])

  const openDoc = useCallback(async (uuid: string) => {
    if (!workspaceUuid) return
    setDocAi(null)
    try {
      setSelected(await knowledgeService.getDocument(workspaceUuid, uuid))
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Failed to open document')
    }
  }, [workspaceUuid])

  const createDoc = useCallback(async () => {
    if (!workspaceUuid || !newTitle.trim()) return
    setBusy(true)
    try {
      const created = await knowledgeService.createDocument(workspaceUuid, { title: newTitle.trim(), body: newBody, status: 'published' })
      setNewTitle('')
      setNewBody('')
      await load()
      await openDoc(created.uuid)
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Failed to create document')
    } finally {
      setBusy(false)
    }
  }, [workspaceUuid, newTitle, newBody, load, openDoc])

  const runDocAi = useCallback(async (kind: 'explain' | 'summarize') => {
    if (!workspaceUuid || !selected) return
    setBusy(true)
    setDocAi(null)
    try {
      const res = kind === 'explain'
        ? await knowledgeService.explain(workspaceUuid, selected.uuid)
        : await knowledgeService.summarize(workspaceUuid, selected.uuid)
      setDocAi('explanation' in res ? res.explanation : res.summary)
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'AI action failed')
    } finally {
      setBusy(false)
    }
  }, [workspaceUuid, selected])

  const runDraft = useCallback(async () => {
    if (!workspaceUuid || !draftTopic.trim()) return
    setBusy(true)
    setDraft(null)
    try {
      setDraft(await knowledgeService.draft(workspaceUuid, draftKind, draftTopic.trim()))
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Draft failed')
    } finally {
      setBusy(false)
    }
  }, [workspaceUuid, draftKind, draftTopic])

  const saveDraft = useCallback(async () => {
    if (!workspaceUuid || !draft) return
    setBusy(true)
    try {
      await knowledgeService.createDocument(workspaceUuid, { ...draft.document_scaffold })
      setDraft(null)
      setTab('documents')
      await load()
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Failed to save draft')
    } finally {
      setBusy(false)
    }
  }, [workspaceUuid, draft, load])

  if (!hasWorkspace) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('knowledge.title')} subtitle={t('knowledge.subtitle')} />
        <EmptyState title={t('knowledge.noWorkspace.title')} description={t('knowledge.noWorkspace.description')} />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <PageHeader title={t('knowledge.title')} subtitle={t('knowledge.subtitle')} />
        <button onClick={() => setCopilotOpen(true)} className="rounded-full border border-amber-400/40 bg-amber-400/10 px-3 py-1.5 text-xs font-semibold text-amber-100 hover:bg-amber-400/20">
          ✨ {t('knowledge.assistant')}
        </button>
      </div>

      {notice ? <p className="text-sm text-amber-300">{notice}</p> : null}

      <div className="flex gap-2 border-b border-white/10">
        {(['documents', 'sources', 'assistant', 'graph'] as Tab[]).map((tk) => (
          <button
            key={tk}
            onClick={() => setTab(tk)}
            className={`px-3 py-2 text-xs font-semibold ${tab === tk ? 'border-b-2 border-sky-400 text-white' : 'text-white/50 hover:text-white'}`}
          >
            {t(`knowledge.tab.${tk}`)}
          </button>
        ))}
      </div>

      {tab === 'documents' ? (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
          <div className="space-y-3">
            <div className="space-y-2 rounded-lg border border-white/10 bg-[#0f151d] p-3">
              <input value={newTitle} onChange={(e) => setNewTitle(e.target.value)} placeholder={t('knowledge.newTitle')} className="w-full rounded-lg border border-white/15 bg-[#0b0f14] px-3 py-2 text-sm text-white placeholder:text-white/30 focus:border-sky-500 focus:outline-none" />
              <textarea value={newBody} onChange={(e) => setNewBody(e.target.value)} placeholder={t('knowledge.newBody')} rows={3} className="w-full rounded-lg border border-white/15 bg-[#0b0f14] px-3 py-2 text-sm text-white placeholder:text-white/30 focus:border-sky-500 focus:outline-none" />
              <button disabled={busy || !newTitle.trim()} onClick={createDoc} className="w-full rounded-lg bg-sky-500 px-4 py-2 text-xs font-semibold text-white hover:bg-sky-400 disabled:opacity-40">{t('knowledge.create')}</button>
            </div>
            {documents.length === 0 ? <p className="text-xs text-white/40">{t('knowledge.empty')}</p> : documents.map((d) => (
              <button key={d.uuid} onClick={() => openDoc(d.uuid)} className={`block w-full rounded-lg border p-3 text-left ${selected?.uuid === d.uuid ? 'border-sky-400/50 bg-sky-400/10' : 'border-white/10 bg-[#0f151d] hover:border-white/20'}`}>
                <div className="text-sm font-medium text-white">{d.title}</div>
                <div className="text-xs text-white/40">{d.category ?? '—'} · {d.status} · {d.source_key}</div>
              </button>
            ))}
          </div>
          <div className="lg:col-span-2">
            {!selected ? (
              <EmptyState title={t('knowledge.selectTitle')} description={t('knowledge.selectDescription')} />
            ) : (
              <div className="space-y-3">
                <div className="flex items-center justify-between">
                  <h2 className="text-base font-semibold text-white">{selected.title}</h2>
                  <div className="flex gap-2">
                    <button disabled={busy} onClick={() => runDocAi('explain')} className="rounded-full border border-sky-400/40 px-3 py-1.5 text-xs text-sky-200 hover:bg-sky-400/10">{t('knowledge.explain')}</button>
                    <button disabled={busy} onClick={() => runDocAi('summarize')} className="rounded-full border border-white/15 px-3 py-1.5 text-xs text-white/70 hover:bg-white/10">{t('knowledge.summarize')}</button>
                  </div>
                </div>
                {docAi ? (
                  <div className="rounded-lg border border-white/10 bg-[#0b0f14] p-3">
                    <p className="whitespace-pre-wrap text-sm text-white/85">{docAi.content ?? docAi.error ?? t('knowledge.noAnswer')}</p>
                    {docAi.ai_enabled === false ? <p className="mt-2 text-[10px] uppercase tracking-wide text-amber-300/80">{t('aiCopilot.mockNotice')}</p> : null}
                  </div>
                ) : null}
                <div className="rounded-lg border border-white/10 bg-[#0f151d] p-3">
                  <p className="whitespace-pre-wrap text-sm text-white/80">{selected.body ?? t('knowledge.noBody')}</p>
                </div>
              </div>
            )}
          </div>
        </div>
      ) : null}

      {tab === 'sources' ? (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {(overview?.sources ?? []).map((s) => (
            <div key={s.key} className="rounded-lg border border-white/10 bg-[#0f151d] p-3">
              <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-white">{s.name}</span>
                <span className={`text-[10px] uppercase ${s.operational ? 'text-emerald-300' : 'text-white/40'}`}>{s.operational ? t('knowledge.operational') : t('knowledge.planned')}</span>
              </div>
              <div className="text-xs text-white/40">{s.category} · {s.document_count} {t('knowledge.docs')}</div>
            </div>
          ))}
        </div>
      ) : null}

      {tab === 'assistant' ? (
        <div className="space-y-4">
          <div className="flex flex-wrap gap-2 rounded-lg border border-white/10 bg-[#0f151d] p-3">
            <select value={draftKind} onChange={(e) => setDraftKind(e.target.value as DraftKind)} className="rounded-lg border border-white/15 bg-[#0b0f14] px-3 py-2 text-sm text-white focus:border-sky-500 focus:outline-none">
              {DRAFT_KINDS.map((k) => <option key={k} value={k}>{t(`knowledge.draftKind.${k}`)}</option>)}
            </select>
            <input value={draftTopic} onChange={(e) => setDraftTopic(e.target.value)} placeholder={t('knowledge.draftTopic')} className="flex-1 rounded-lg border border-white/15 bg-[#0b0f14] px-3 py-2 text-sm text-white placeholder:text-white/30 focus:border-sky-500 focus:outline-none" />
            <button disabled={busy || !draftTopic.trim()} onClick={runDraft} className="rounded-lg bg-sky-500 px-4 py-2 text-xs font-semibold text-white hover:bg-sky-400 disabled:opacity-40">{t('knowledge.generateDraft')}</button>
          </div>
          {draft ? (
            <div className="space-y-2 rounded-lg border border-white/10 bg-[#0b0f14] p-3">
              <p className="text-[10px] uppercase tracking-wide text-white/40">{draft.kind} · {draft.topic}</p>
              <p className="whitespace-pre-wrap text-sm text-white/85">{draft.ai_draft.content ?? draft.ai_draft.error ?? t('knowledge.noAnswer')}</p>
              {draft.ai_draft.ai_enabled === false ? <p className="text-[10px] uppercase tracking-wide text-amber-300/80">{t('aiCopilot.mockNotice')}</p> : null}
              <p className="text-xs text-white/50">{draft.note}</p>
              <button disabled={busy} onClick={saveDraft} className="rounded-lg border border-emerald-400/40 px-4 py-2 text-xs font-semibold text-emerald-200 hover:bg-emerald-400/10">{t('knowledge.saveDraft')}</button>
            </div>
          ) : <p className="text-xs text-white/40">{t('knowledge.assistantHint')}</p>}
        </div>
      ) : null}

      {tab === 'graph' ? <KnowledgeGraphView workspaceUuid={workspaceUuid} /> : null}

      <AiCopilotDrawer
        open={copilotOpen}
        onClose={() => setCopilotOpen(false)}
        workspaceUuid={workspaceUuid}
        copilot={(w, message, conversation) => knowledgeService.copilot(w, message, conversation)}
        title={t('knowledge.assistantTitle')}
        introText={t('knowledge.assistantIntro')}
      />
    </div>
  )
}

function KnowledgeGraphView({ workspaceUuid }: { workspaceUuid: string }) {
  const { t } = useLanguage()
  const [counts, setCounts] = useState<Record<string, number> | null>(null)
  const [edgeCount, setEdgeCount] = useState(0)

  useEffect(() => {
    let active = true
    void knowledgeService.graph(workspaceUuid).then((g) => {
      if (active) {
        setCounts(g.counts_by_type)
        setEdgeCount(g.edge_count)
      }
    }).catch(() => undefined)
    return () => { active = false }
  }, [workspaceUuid])

  if (!counts) return <p className="text-xs text-white/40">{t('knowledge.loading')}</p>

  return (
    <div className="space-y-3">
      <p className="text-xs text-white/50">{t('knowledge.graphEdges')}: {edgeCount}</p>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        {Object.entries(counts).map(([type, n]) => (
          <div key={type} className="rounded-lg border border-white/10 bg-[#0f151d] p-3 text-center">
            <div className="text-lg font-semibold text-white">{n}</div>
            <div className="text-[10px] uppercase tracking-wide text-white/40">{type}</div>
          </div>
        ))}
      </div>
    </div>
  )
}
