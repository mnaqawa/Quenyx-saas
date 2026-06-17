import { useReports, useReportSummary } from '../../hooks/useObserveData'
import { PageHeader } from '../../components/observe/PageHeader'
import { StatusBadge } from '../../components/observe/StatusBadge'
import { useLanguage } from '../../i18n/LanguageContext'

export default function Reports() {
  const { t } = useLanguage()
  const { reports, loading: reportsLoading } = useReports()
  const { summary, loading: summaryLoading } = useReportSummary()

  if (reportsLoading || summaryLoading) {
    return <div className="text-sm text-white/60">{t('common.loading')}</div>
  }

  const hasExports = (summary?.total ?? 0) > 0 || reports.length > 0
  const backendAvailable = summary?.backend_available !== false

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('reports.title')}
        subtitle={t('reports.subtitle')}
        actions={
          <button
            title={t('reports.generateDisabledHint')}
            disabled
            className="cursor-not-allowed rounded-lg bg-sky-500/50 px-4 py-1.5 text-xs font-semibold text-white/70"
          >
            {t('reports.generate')}
          </button>
        }
      />

      {!backendAvailable && (
        <div className="rounded-2xl border border-amber-500/30 bg-amber-500/5 p-6 text-sm text-amber-100/90">
          <p className="font-medium">{t('reports.noBackendTitle')}</p>
          <p className="mt-2 text-white/70">{t('reports.noBackendBody')}</p>
        </div>
      )}

      {!hasExports ? (
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-12 text-center text-white">
          <p className="text-sm font-medium text-white/80">{t('reports.emptyTitle')}</p>
          <p className="mt-2 text-xs text-white/50">{t('reports.emptyBody')}</p>
        </div>
      ) : (
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <h3 className="mb-1 text-sm font-semibold">{t('reports.exportHistory')}</h3>
          <p className="mb-4 text-xs text-white/50">{t('reports.exportHistoryHint')}</p>
          <div className="space-y-3">
            {reports.map((report) => (
              <div
                key={report.id}
                className="flex items-center gap-4 rounded-lg border border-white/5 bg-white/5 p-4"
              >
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-sky-500/20 text-sky-200">
                  JSON
                </div>
                <div className="flex-1">
                  <h4 className="text-sm font-semibold">{report.name}</h4>
                  <p className="text-xs text-white/60">
                    {report.category} • {report.date ? new Date(report.date).toLocaleString() : '—'} • {report.size}
                  </p>
                </div>
                <StatusBadge status={report.status === 'failed' ? 'error' : 'ok'} label={report.status} />
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
