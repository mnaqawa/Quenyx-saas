import { useCallback, useEffect, useState } from 'react'
import { PageHeader } from '../../components/observe/PageHeader'
import { StatCard } from '../../components/observe/StatCard'
import { EmptyState } from '../../components/observe/capacity/EmptyState'
import { useLanguage } from '../../i18n/LanguageContext'
import { useAiResource, useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { AiCopilotDrawer } from '../../components/ai/AiCopilotDrawer'
import { automationService } from '../../services/automationService'
import type {
  ApprovalSummary,
  ExecutionSummary,
  RunbookSuggestion,
  RunbookSummary,
  WorkflowSummary,
} from '../../types/automation'

type Tab = 'overview' | 'library' | 'workflows' | 'runbooks' | 'executions' | 'approvals' | 'learning'

const STATUS_CLASS: Record<string, string> = {
  succeeded: 'text-emerald-300',
  dry_run: 'text-sky-300',
  failed: 'text-rose-300',
  skipped: 'text-amber-300',
  awaiting_approval: 'text-amber-300',
  rolled_back: 'text-fuchsia-300',
  cancelled: 'text-white/40',
}

export default function AutomationDashboard() {
  const { t } = useLanguage()
  const { workspaceUuid, hasWorkspace } = useAiWorkspaceUuid()
  const { data, loading, error, reload } = useAiResource((w) => automationService.getOverview(w), [])

  const [tab, setTab] = useState<Tab>('overview')
  const [copilotOpen, setCopilotOpen] = useState(false)

  const [workflows, setWorkflows] = useState<WorkflowSummary[]>([])
  const [runbooks, setRunbooks] = useState<RunbookSummary[]>([])
  const [executions, setExecutions] = useState<ExecutionSummary[]>([])
  const [approvals, setApprovals] = useState<ApprovalSummary[]>([])
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)

  const [problem, setProblem] = useState('')
  const [suggestion, setSuggestion] = useState<RunbookSuggestion | null>(null)

  const loadTab = useCallback(
    async (next: Tab) => {
      if (!workspaceUuid) return
      setNotice(null)
      try {
        if (next === 'workflows') setWorkflows((await automationService.listWorkflows(workspaceUuid)).workflows)
        if (next === 'runbooks') setRunbooks((await automationService.listRunbooks(workspaceUuid)).runbooks)
        if (next === 'executions') setExecutions((await automationService.listExecutions(workspaceUuid)).executions)
        if (next === 'approvals') setApprovals((await automationService.listApprovals(workspaceUuid)).approvals)
      } catch (err) {
        setNotice(err instanceof Error ? err.message : 'Failed to load')
      }
    },
    [workspaceUuid]
  )

  useEffect(() => {
    void loadTab(tab)
  }, [tab, loadTab])

  const runExecution = useCallback(
    async (uuid: string, action: 'rollback') => {
      if (!workspaceUuid) return
      setBusy(true)
      setNotice(null)
      try {
        if (action === 'rollback') await automationService.rollback(workspaceUuid, uuid)
        await loadTab('executions')
      } catch (err) {
        setNotice(err instanceof Error ? err.message : 'Action failed')
      } finally {
        setBusy(false)
      }
    },
    [workspaceUuid, loadTab]
  )

  const decide = useCallback(
    async (uuid: string, approve: boolean) => {
      if (!workspaceUuid) return
      setBusy(true)
      setNotice(null)
      try {
        await automationService.decideApproval(workspaceUuid, uuid, approve)
        await loadTab('approvals')
        reload()
      } catch (err) {
        setNotice(err instanceof Error ? err.message : 'Decision failed')
      } finally {
        setBusy(false)
      }
    },
    [workspaceUuid, loadTab, reload]
  )

  const draftRunbook = useCallback(async () => {
    if (!workspaceUuid || !problem.trim()) return
    setBusy(true)
    setNotice(null)
    try {
      setSuggestion(await automationService.suggestRunbook(workspaceUuid, problem.trim()))
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Draft failed')
    } finally {
      setBusy(false)
    }
  }, [workspaceUuid, problem])

  if (!hasWorkspace) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('automation.title')} subtitle={t('automation.subtitle')} />
        <EmptyState title={t('automation.noWorkspace.title')} description={t('automation.noWorkspace.description')} />
      </div>
    )
  }

  const tabs: Tab[] = ['overview', 'library', 'workflows', 'runbooks', 'executions', 'approvals', 'learning']

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('automation.title')}
        subtitle={t('automation.subtitle')}
        actions={
          <button
            onClick={() => setCopilotOpen(true)}
            className="inline-flex items-center gap-1.5 rounded-full border border-amber-400/40 bg-amber-400/10 px-4 py-2 text-sm font-semibold text-amber-100 hover:bg-amber-400/20"
          >
            <span aria-hidden>✨</span>
            {t('automation.askCopilot')}
          </button>
        }
      />

      {data && !data.live_execution_enabled ? (
        <div className="rounded-lg border border-sky-400/30 bg-sky-400/10 px-4 py-2 text-xs text-sky-100">
          {t('automation.dryRunBanner')}
        </div>
      ) : null}

      {loading ? <p className="text-sm text-white/50">{t('automation.loading')}</p> : null}
      {error ? <p className="text-sm text-rose-300">{error}</p> : null}
      {notice ? <p className="text-sm text-amber-300">{notice}</p> : null}

      {data ? (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard title={t('automation.stat.workflows')} value={String(data.counts.workflows)} />
          <StatCard title={t('automation.stat.runbooks')} value={String(data.counts.runbooks)} />
          <StatCard title={t('automation.stat.executions')} value={String(data.counts.executions)} />
          <StatCard title={t('automation.stat.pendingApprovals')} value={String(data.counts.pending_approvals)} />
        </div>
      ) : null}

      <div className="flex flex-wrap gap-2 border-b border-white/10 pb-2">
        {tabs.map((x) => (
          <button
            key={x}
            onClick={() => setTab(x)}
            className={`rounded-full px-3 py-1.5 text-xs font-medium ${tab === x ? 'bg-sky-500 text-white' : 'border border-white/10 text-white/60 hover:text-white'}`}
          >
            {t(`automation.tab.${x}`)}
          </button>
        ))}
      </div>

      {tab === 'overview' && data ? (
        <div className="space-y-4">
          <h3 className="text-sm font-semibold text-white">{t('automation.recentExecutions')}</h3>
          <ExecutionTable rows={data.recent_executions} t={t} />
        </div>
      ) : null}

      {tab === 'library' && data ? (
        <div className="space-y-6">
          <section>
            <h3 className="mb-2 text-sm font-semibold text-white">{t('automation.adapters')}</h3>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {data.adapters.map((a) => (
                <div key={a.key} className="rounded-lg border border-white/10 bg-[#0f151d] p-3">
                  <div className="flex items-center justify-between">
                    <span className="text-sm font-semibold text-white">{a.name}</span>
                    <span className={`text-[10px] uppercase ${a.operational ? 'text-emerald-300' : 'text-white/40'}`}>
                      {a.operational ? t('automation.operational') : t('automation.planned')}
                    </span>
                  </div>
                  <p className="mt-1 text-xs text-white/50">{a.description}</p>
                  <p className="mt-2 text-[10px] uppercase tracking-wide text-white/30">{a.category} · {a.capabilities.join(', ')}</p>
                </div>
              ))}
            </div>
          </section>
          <section>
            <h3 className="mb-2 text-sm font-semibold text-white">{t('automation.actionCatalog')}</h3>
            <div className="overflow-x-auto rounded-lg border border-white/10">
              <table className="min-w-full text-left text-xs">
                <thead className="bg-white/5 text-white/50">
                  <tr><th className="px-3 py-2">{t('automation.col.action')}</th><th className="px-3 py-2">{t('automation.col.adapter')}</th><th className="px-3 py-2">{t('automation.col.category')}</th><th className="px-3 py-2">{t('automation.col.destructive')}</th></tr>
                </thead>
                <tbody>
                  {data.action_catalog.map((ac) => (
                    <tr key={ac.key} className="border-t border-white/5">
                      <td className="px-3 py-2 text-white/90">{ac.label}</td>
                      <td className="px-3 py-2 text-white/60">{ac.adapter_key}</td>
                      <td className="px-3 py-2 text-white/60">{ac.category}</td>
                      <td className="px-3 py-2">{ac.destructive ? <span className="text-rose-300">{t('automation.requiresApproval')}</span> : <span className="text-emerald-300">{t('automation.safe')}</span>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        </div>
      ) : null}

      {tab === 'workflows' ? (
        workflows.length ? (
          <div className="space-y-2">
            {workflows.map((w) => (
              <div key={w.uuid} className="flex items-center justify-between rounded-lg border border-white/10 bg-[#0f151d] p-3">
                <div>
                  <div className="text-sm font-semibold text-white">{w.name}</div>
                  <div className="text-xs text-white/50">{w.trigger_type} · {w.action_count} {t('automation.actions')}</div>
                </div>
                <button
                  disabled={busy}
                  onClick={async () => { if (workspaceUuid) { await automationService.runWorkflow(workspaceUuid, w.uuid, 'dry_run'); await loadTab('executions'); setTab('executions') } }}
                  className="rounded-full border border-sky-400/40 px-3 py-1.5 text-xs text-sky-200 hover:bg-sky-400/10"
                >
                  {t('automation.runDryRun')}
                </button>
              </div>
            ))}
          </div>
        ) : <EmptyState title={t('automation.empty.workflows')} description={t('automation.empty.workflowsDesc')} />
      ) : null}

      {tab === 'runbooks' ? (
        <div className="space-y-4">
          <div className="rounded-lg border border-amber-400/30 bg-amber-400/5 p-4">
            <h3 className="text-sm font-semibold text-amber-100">{t('automation.draftRunbook')}</h3>
            <p className="mt-1 text-xs text-white/50">{t('automation.draftRunbookHint')}</p>
            <div className="mt-3 flex gap-2">
              <input
                value={problem}
                onChange={(e) => setProblem(e.target.value)}
                placeholder={t('automation.draftPlaceholder')}
                className="flex-1 rounded-lg border border-white/15 bg-[#0f151d] px-3 py-2 text-sm text-white placeholder:text-white/30 focus:border-sky-500 focus:outline-none"
              />
              <button disabled={busy || !problem.trim()} onClick={draftRunbook} className="rounded-lg bg-sky-500 px-4 py-2 text-xs font-semibold text-white hover:bg-sky-400 disabled:opacity-40">
                {t('automation.draft')}
              </button>
            </div>
            {suggestion ? (
              <div className="mt-3 rounded-lg border border-white/10 bg-[#0b0f14] p-3">
                <p className="text-xs text-white/70">{suggestion.ai_rationale.content ?? t('automation.draftReady')}</p>
                <pre className="mt-2 max-h-64 overflow-auto whitespace-pre-wrap rounded bg-black/40 p-2 text-[11px] text-white/70">{JSON.stringify(suggestion.suggested_runbook, null, 2)}</pre>
                <p className="mt-2 text-[10px] uppercase tracking-wide text-amber-300/80">{suggestion.note}</p>
              </div>
            ) : null}
          </div>
          {runbooks.length ? (
            <div className="space-y-2">
              {runbooks.map((r) => (
                <div key={r.uuid} className="flex items-center justify-between rounded-lg border border-white/10 bg-[#0f151d] p-3">
                  <div>
                    <div className="text-sm font-semibold text-white">{r.name}</div>
                    <div className="text-xs text-white/50">{r.category ?? '—'} · {r.step_count} {t('automation.steps')} · {r.source}</div>
                  </div>
                  <button
                    disabled={busy}
                    onClick={async () => { if (workspaceUuid) { await automationService.runRunbook(workspaceUuid, r.uuid, 'dry_run'); await loadTab('executions'); setTab('executions') } }}
                    className="rounded-full border border-sky-400/40 px-3 py-1.5 text-xs text-sky-200 hover:bg-sky-400/10"
                  >
                    {t('automation.runDryRun')}
                  </button>
                </div>
              ))}
            </div>
          ) : <EmptyState title={t('automation.empty.runbooks')} description={t('automation.empty.runbooksDesc')} />}
        </div>
      ) : null}

      {tab === 'executions' ? (
        executions.length ? <ExecutionTable rows={executions} t={t} onRollback={(u) => runExecution(u, 'rollback')} /> : <EmptyState title={t('automation.empty.executions')} description={t('automation.empty.executionsDesc')} />
      ) : null}

      {tab === 'approvals' ? (
        approvals.length ? (
          <div className="space-y-2">
            {approvals.map((a) => (
              <div key={a.uuid} className="flex items-center justify-between rounded-lg border border-amber-400/30 bg-amber-400/5 p-3">
                <div>
                  <div className="text-sm font-semibold text-white">{a.execution?.action_key ?? a.execution?.adapter_key ?? t('automation.execution')}</div>
                  <div className="text-xs text-white/50">{a.execution?.mode} · {a.created_at}</div>
                </div>
                <div className="flex gap-2">
                  <button disabled={busy} onClick={() => decide(a.uuid, true)} className="rounded-full bg-emerald-500/80 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-500">{t('automation.approve')}</button>
                  <button disabled={busy} onClick={() => decide(a.uuid, false)} className="rounded-full border border-rose-400/40 px-3 py-1.5 text-xs text-rose-200 hover:bg-rose-400/10">{t('automation.reject')}</button>
                </div>
              </div>
            ))}
          </div>
        ) : <EmptyState title={t('automation.empty.approvals')} description={t('automation.empty.approvalsDesc')} />
      ) : null}

      {tab === 'learning' && data ? (
        data.learning.actions.length ? (
          <div className="overflow-x-auto rounded-lg border border-white/10">
            <table className="min-w-full text-left text-xs">
              <thead className="bg-white/5 text-white/50">
                <tr><th className="px-3 py-2">{t('automation.col.action')}</th><th className="px-3 py-2">{t('automation.col.total')}</th><th className="px-3 py-2">{t('automation.col.successRate')}</th><th className="px-3 py-2">{t('automation.col.avgDuration')}</th></tr>
              </thead>
              <tbody>
                {data.learning.actions.map((s) => (
                  <tr key={s.action_key} className="border-t border-white/5">
                    <td className="px-3 py-2 text-white/90">{s.action_key}</td>
                    <td className="px-3 py-2 text-white/60">{s.total}</td>
                    <td className="px-3 py-2 text-white/60">{s.success_rate}%</td>
                    <td className="px-3 py-2 text-white/60">{s.avg_duration_ms ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : <EmptyState title={t('automation.empty.learning')} description={t('automation.empty.learningDesc')} />
      ) : null}

      <AiCopilotDrawer
        open={copilotOpen}
        onClose={() => setCopilotOpen(false)}
        workspaceUuid={workspaceUuid}
        copilot={(w, message, conversation) => automationService.copilot(w, message, conversation)}
        title={t('automation.copilotTitle')}
        introText={t('automation.copilotIntro')}
      />
    </div>
  )
}

function ExecutionTable({ rows, t, onRollback }: { rows: ExecutionSummary[]; t: (k: string) => string; onRollback?: (uuid: string) => void }) {
  if (!rows.length) return <p className="text-xs text-white/40">{t('automation.empty.executions')}</p>
  return (
    <div className="overflow-x-auto rounded-lg border border-white/10">
      <table className="min-w-full text-left text-xs">
        <thead className="bg-white/5 text-white/50">
          <tr>
            <th className="px-3 py-2">{t('automation.col.adapter')}</th>
            <th className="px-3 py-2">{t('automation.col.action')}</th>
            <th className="px-3 py-2">{t('automation.col.status')}</th>
            <th className="px-3 py-2">{t('automation.col.mode')}</th>
            {onRollback ? <th className="px-3 py-2" /> : null}
          </tr>
        </thead>
        <tbody>
          {rows.map((e) => (
            <tr key={e.uuid} className="border-t border-white/5">
              <td className="px-3 py-2 text-white/80">{e.adapter_key}</td>
              <td className="px-3 py-2 text-white/60">{e.action_key ?? '—'}</td>
              <td className={`px-3 py-2 ${STATUS_CLASS[e.status] ?? 'text-white/60'}`}>{e.status}</td>
              <td className="px-3 py-2 text-white/50">{e.mode}</td>
              {onRollback ? (
                <td className="px-3 py-2">
                  {e.status === 'succeeded' && !e.rolled_back ? (
                    <button onClick={() => onRollback(e.uuid)} className="rounded-full border border-fuchsia-400/40 px-2 py-1 text-[10px] text-fuchsia-200 hover:bg-fuchsia-400/10">{t('automation.rollback')}</button>
                  ) : null}
                </td>
              ) : null}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
