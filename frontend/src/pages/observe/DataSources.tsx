import { useDataSources, useDataSourceSummary } from '../../hooks/useObserveData'
import { PageHeader } from '../../components/observe/PageHeader'
import { StatusBadge } from '../../components/observe/StatusBadge'
import { useLanguage } from '../../i18n/LanguageContext'

export default function DataSources() {
  const { t } = useLanguage()
  const { sources, loading: sourcesLoading } = useDataSources()
  const { summary, loading: summaryLoading } = useDataSourceSummary()

  if (sourcesLoading || summaryLoading) {
    return <div className="text-sm text-white/60">{t('common.loading')}</div>
  }

  const formatRecordCount = (count: number) => {
    if (count >= 1000000) return `${(count / 1000000).toFixed(1)}M`
    if (count >= 1000) return `${(count / 1000).toFixed(0)}K`
    return count.toString()
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('dataSources.title')}
        subtitle={t('dataSources.subtitle')}
        actions={
          <button
            title={t('dataSources.addDisabledHint')}
            disabled
            className="cursor-not-allowed rounded-lg bg-sky-500/50 px-4 py-1.5 text-xs font-semibold text-white/70"
          >
            {t('dataSources.add')}
          </button>
        }
      />

      <div className="rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-xs text-white/60">
        {t('dataSources.clarification')}
      </div>

      {sources.length === 0 ? (
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-12 text-center text-white">
          <p className="text-sm font-medium text-white/80">{t('dataSources.emptyTitle')}</p>
          <p className="mt-2 text-xs text-white/50">{t('dataSources.emptyBody')}</p>
        </div>
      ) : (
        <>
          <div className="grid gap-4 md:grid-cols-4">
            <div className="rounded-xl border border-white/10 bg-[#0f151d] p-4">
              <p className="text-xs text-white/50">{t('dataSources.connected')}</p>
              <p className="mt-1 text-2xl font-semibold text-white">{summary?.connected ?? 0}</p>
            </div>
            <div className="rounded-xl border border-white/10 bg-[#0f151d] p-4">
              <p className="text-xs text-white/50">{t('dataSources.totalRecords')}</p>
              <p className="mt-1 text-2xl font-semibold text-white">{summary?.totalRecords ?? '0'}</p>
            </div>
            <div className="rounded-xl border border-white/10 bg-[#0f151d] p-4">
              <p className="text-xs text-white/50">{t('dataSources.syncStatus')}</p>
              <p className="mt-1 text-2xl font-semibold text-white">{summary?.syncStatus ?? 0}%</p>
            </div>
            <div className="rounded-xl border border-white/10 bg-[#0f151d] p-4">
              <p className="text-xs text-white/50">{t('dataSources.lastUpdate')}</p>
              <p className="mt-1 text-lg font-semibold text-white">{summary?.lastUpdate ?? '—'}</p>
            </div>
          </div>

          <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
            <h3 className="mb-4 text-sm font-semibold">{t('dataSources.connections')}</h3>
            <div className="space-y-3">
              {sources.map((source) => (
                <div
                  key={source.id}
                  className="flex items-center gap-4 rounded-lg border border-white/5 bg-white/5 p-4"
                >
                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-white/5 text-lg">
                    {source.type === 'agent_metrics' ? '📡' : source.type === 'billing_feed' ? '💳' : '🗺️'}
                  </div>
                  <div className="flex-1">
                    <h4 className="text-sm font-semibold">{source.name}</h4>
                    <p className="text-xs text-white/60">
                      {t(`dataSources.type.${source.type}`)} • {formatRecordCount(source.recordCount)} {t('dataSources.records')} • {t('dataSources.lastSync')}: {source.lastSync}
                    </p>
                  </div>
                  <StatusBadge status={source.status === 'connected' ? 'ok' : source.status} label={source.status} />
                </div>
              ))}
            </div>
          </div>
        </>
      )}
    </div>
  )
}
