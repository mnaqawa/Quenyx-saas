import { useCallback, useState } from 'react'
import { PageHeader } from '../../components/observe/PageHeader'
import { EmptyState } from '../../components/observe/capacity/EmptyState'
import { useLanguage } from '../../i18n/LanguageContext'
import { useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { knowledgeService } from '../../services/knowledgeService'
import type { SearchResult } from '../../types/knowledge'

const TYPE_CLASS: Record<string, string> = {
  document: 'text-sky-300',
  incident: 'text-rose-300',
  ticket: 'text-amber-300',
  notification: 'text-fuchsia-300',
  workflow: 'text-emerald-300',
  runbook: 'text-teal-300',
}

export default function EnterpriseSearch() {
  const { t } = useLanguage()
  const { workspaceUuid, hasWorkspace } = useAiWorkspaceUuid()

  const [query, setQuery] = useState('')
  const [mode, setMode] = useState<'keyword' | 'semantic'>('keyword')
  const [result, setResult] = useState<SearchResult | null>(null)
  const [loading, setLoading] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)

  const run = useCallback(async () => {
    if (!workspaceUuid || !query.trim()) return
    setLoading(true)
    setNotice(null)
    try {
      setResult(await knowledgeService.search(workspaceUuid, query.trim(), { mode }))
    } catch (err) {
      setNotice(err instanceof Error ? err.message : 'Search failed')
    } finally {
      setLoading(false)
    }
  }, [workspaceUuid, query, mode])

  if (!hasWorkspace) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('search.title')} subtitle={t('search.subtitle')} />
        <EmptyState title={t('search.noWorkspace.title')} description={t('search.noWorkspace.description')} />
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader title={t('search.title')} subtitle={t('search.subtitle')} />

      {notice ? <p className="text-sm text-amber-300">{notice}</p> : null}

      <form className="flex gap-2" onSubmit={(e) => { e.preventDefault(); void run() }}>
        <input
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder={t('search.placeholder')}
          className="flex-1 rounded-lg border border-white/15 bg-[#0f151d] px-3 py-2 text-sm text-white placeholder:text-white/30 focus:border-sky-500 focus:outline-none"
        />
        <select value={mode} onChange={(e) => setMode(e.target.value as 'keyword' | 'semantic')} className="rounded-lg border border-white/15 bg-[#0f151d] px-3 py-2 text-sm text-white focus:border-sky-500 focus:outline-none">
          <option value="keyword">{t('search.keyword')}</option>
          <option value="semantic">{t('search.semantic')}</option>
        </select>
        <button disabled={loading || !query.trim()} className="rounded-lg bg-sky-500 px-5 py-2 text-xs font-semibold text-white hover:bg-sky-400 disabled:opacity-40">{t('search.go')}</button>
      </form>

      {loading ? <p className="text-xs text-white/40">{t('search.loading')}</p> : null}

      {result ? (
        <div className="space-y-3">
          <p className="text-xs text-white/50">{result.total} {t('search.results')} · {t('search.sources')}: {result.searched_sources.join(', ') || '—'}</p>
          {result.results.length === 0 ? <p className="text-xs text-white/40">{t('search.empty')}</p> : result.results.map((hit, idx) => (
            <div key={`${hit.type}-${hit.uuid}-${idx}`} className="rounded-lg border border-white/10 bg-[#0f151d] p-3">
              <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-white">{hit.title}</span>
                <span className={`text-[10px] uppercase ${TYPE_CLASS[hit.type] ?? 'text-white/50'}`}>{hit.type} · {hit.module}</span>
              </div>
              {hit.snippet ? <p className="mt-1 text-xs text-white/60">{hit.snippet}</p> : null}
              <p className="mt-1 text-[10px] text-white/30">{t('search.score')}: {hit.score}</p>
            </div>
          ))}
        </div>
      ) : null}
    </div>
  )
}
