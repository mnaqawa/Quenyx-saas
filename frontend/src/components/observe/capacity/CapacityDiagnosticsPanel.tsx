import type { CapacityDiagnostics } from '../../../types/observe'

interface CapacityDiagnosticsPanelProps {
  diagnostics: CapacityDiagnostics
  labels: {
    title: string
    historyAvailable: string
    totalSamples: string
    hostsWithMetrics: string
    configuredHosts: string
    oldestSample: string
    newestSample: string
    supportedMetrics: string
    insufficientReasons: string
    yes: string
    no: string
  }
}

export function CapacityDiagnosticsPanel({ diagnostics, labels }: CapacityDiagnosticsPanelProps) {
  return (
    <div className="rounded-xl border border-white/10 bg-white/[0.03] p-4 text-white">
      <h3 className="mb-3 text-xs font-semibold uppercase tracking-wide text-white/50">{labels.title}</h3>
      <dl className="grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-3">
        <div>
          <dt className="text-white/45">{labels.historyAvailable}</dt>
          <dd>{diagnostics.metrics_history_available ? labels.yes : labels.no}</dd>
        </div>
        <div>
          <dt className="text-white/45">{labels.totalSamples}</dt>
          <dd className="tabular-nums">{diagnostics.total_samples}</dd>
        </div>
        <div>
          <dt className="text-white/45">{labels.hostsWithMetrics}</dt>
          <dd className="tabular-nums">{diagnostics.hosts_with_metrics}</dd>
        </div>
        <div>
          <dt className="text-white/45">{labels.configuredHosts}</dt>
          <dd className="tabular-nums">{diagnostics.configured_target_hosts ?? 0}</dd>
        </div>
        <div>
          <dt className="text-white/45">{labels.oldestSample}</dt>
          <dd>{diagnostics.oldest_sample_at ? new Date(diagnostics.oldest_sample_at).toLocaleString() : '—'}</dd>
        </div>
        <div>
          <dt className="text-white/45">{labels.newestSample}</dt>
          <dd>{diagnostics.newest_sample_at ? new Date(diagnostics.newest_sample_at).toLocaleString() : '—'}</dd>
        </div>
        <div>
          <dt className="text-white/45">{labels.supportedMetrics}</dt>
          <dd>{diagnostics.supported_metrics.join(', ')}</dd>
        </div>
      </dl>
      {diagnostics.insufficient_data_reasons.length > 0 ? (
        <div className="mt-3 border-t border-white/10 pt-3">
          <p className="text-[10px] font-semibold uppercase text-white/45">{labels.insufficientReasons}</p>
          <ul className="mt-1 list-disc space-y-1 ps-4 text-xs text-white/60">
            {diagnostics.insufficient_data_reasons.map((reason) => (
              <li key={reason}>{reason}</li>
            ))}
          </ul>
        </div>
      ) : null}
    </div>
  )
}
