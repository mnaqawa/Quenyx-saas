import { useState } from 'react'
import type { ReactNode } from 'react'
import { PageHeader } from '../../components/observe/PageHeader'
import { StatCard } from '../../components/observe/StatCard'
import { EmptyState } from '../../components/observe/capacity/EmptyState'
import { useLanguage } from '../../i18n/LanguageContext'
import { useAiResource, useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { assetIntelligenceService } from '../../services/assetIntelligenceService'
import { AiCopilotDrawer } from '../../components/ai/AiCopilotDrawer'
import { AssetAiButton } from '../../components/asset/intelligence/AssetAiButton'
import type { AssetSummary } from '../../types/assetIntelligence'

const SEVERITY_CLASS: Record<string, string> = {
  critical: 'text-rose-300',
  warning: 'text-amber-300',
  info: 'text-sky-300',
  healthy: 'text-emerald-300',
}

const CONFIDENCE_CLASS: Record<string, string> = {
  high: 'text-emerald-300',
  medium: 'text-amber-300',
  low: 'text-white/50',
}

function assetQuestion(asset: AssetSummary): string {
  return `Explain asset "${asset.name}": discovery confidence, current activity, hardware facts collected, dependencies, and any gaps — using only the evidence.`
}

export default function AssetIntelligence() {
  const { t } = useLanguage()
  const { workspaceUuid, hasWorkspace } = useAiWorkspaceUuid()
  const { data, loading, error } = useAiResource((ws) => assetIntelligenceService.getOverview(ws), [])

  const [copilotOpen, setCopilotOpen] = useState(false)

  if (!hasWorkspace) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('assetIntel.title')} subtitle={t('assetIntel.subtitle')} />
        <EmptyState title={t('assetIntel.noWorkspace.title')} description={t('assetIntel.noWorkspace.description')} />
      </div>
    )
  }

  const summary = data?.inventory_summary
  const discovery = data?.discovery

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('assetIntel.title')}
        subtitle={t('assetIntel.subtitle')}
        actions={
          <button
            onClick={() => setCopilotOpen(true)}
            className="inline-flex items-center gap-1.5 rounded-full border border-amber-400/40 bg-amber-400/10 px-4 py-2 text-sm font-semibold text-amber-100 hover:bg-amber-400/20"
          >
            <span aria-hidden>✨</span>
            {t('assetIntel.askCopilot')}
          </button>
        }
      />

      {loading ? <p className="text-sm text-white/50">{t('assetIntel.loading')}</p> : null}
      {error ? <p className="text-sm text-rose-300">{error}</p> : null}

      {data && !loading ? (
        <>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard title={t('assetIntel.stat.totalAssets')} value={String(summary?.total ?? 0)} detail={`${summary?.enabled ?? 0} ${t('assetIntel.stat.enabled')}`} />
            <StatCard title={t('assetIntel.stat.withAgent')} value={String(summary?.with_agent ?? 0)} detail={`${summary?.without_agent ?? 0} ${t('assetIntel.stat.withoutAgent')}`} />
            <StatCard title={t('assetIntel.stat.online')} value={String(summary?.online ?? 0)} />
            <StatCard title={t('assetIntel.stat.inactive')} value={String(summary?.inactive ?? 0)} />
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard title={t('assetIntel.stat.newAssets')} value={String(discovery?.new_asset_count ?? 0)} />
            <StatCard title={t('assetIntel.stat.changedAssets')} value={String(discovery?.changed_asset_count ?? 0)} />
            <StatCard title={t('assetIntel.stat.unknownAssets')} value={String(discovery?.unknown_asset_count ?? 0)} />
            <StatCard title={t('assetIntel.stat.duplicates')} value={String(discovery?.duplicate_count ?? 0)} />
          </div>

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            {/* Inventory by OS */}
            <Panel title={t('assetIntel.section.byOs')}>
              {summary && Object.keys(summary.by_os).length > 0 ? (
                <ul className="space-y-2">
                  {Object.entries(summary.by_os).map(([os, count]) => (
                    <li key={os} className="flex items-center justify-between text-sm">
                      <span className="text-white/80">{os}</span>
                      <span className="text-white/50">{count}</span>
                    </li>
                  ))}
                </ul>
              ) : (
                <Muted>{t('assetIntel.empty.assets')}</Muted>
              )}
            </Panel>

            {/* Discovery confidence */}
            <Panel title={t('assetIntel.section.confidence')}>
              {summary ? (
                <ul className="space-y-2">
                  {(['high', 'medium', 'low'] as const).map((level) => (
                    <li key={level} className="flex items-center justify-between text-sm">
                      <span className={`capitalize ${CONFIDENCE_CLASS[level]}`}>{t(`assetIntel.confidence.${level}`)}</span>
                      <span className="text-white/50">{summary.discovery_confidence[level]}</span>
                    </li>
                  ))}
                </ul>
              ) : (
                <Muted>{t('assetIntel.empty.assets')}</Muted>
              )}
            </Panel>

            {/* Inactive assets with contextual Explain */}
            <Panel title={t('assetIntel.section.inactive')}>
              {discovery && discovery.inactive_assets.length > 0 ? (
                <ul className="space-y-2">
                  {discovery.inactive_assets.map((a) => (
                    <li key={a.uuid} className="flex items-center justify-between gap-2 text-sm">
                      <span className="min-w-0 truncate">
                        <span className="text-white/80">{a.name}</span>
                        {a.address ? <span className="text-white/40"> · {a.address}</span> : null}
                      </span>
                      <AssetAiButton label={t('ai.action.explain')} question={assetQuestion(a)} />
                    </li>
                  ))}
                </ul>
              ) : (
                <Muted>{t('assetIntel.empty.inactive')}</Muted>
              )}
            </Panel>

            {/* Newly discovered assets with contextual Explain */}
            <Panel title={t('assetIntel.section.new')}>
              {discovery && discovery.new_assets.length > 0 ? (
                <ul className="space-y-2">
                  {discovery.new_assets.map((a) => (
                    <li key={a.uuid} className="flex items-center justify-between gap-2 text-sm">
                      <span className="min-w-0 truncate">
                        <span className="text-white/80">{a.name}</span>
                        <span className={`ml-2 text-xs ${CONFIDENCE_CLASS[a.discovery_confidence]}`}>{t(`assetIntel.confidence.${a.discovery_confidence}`)}</span>
                      </span>
                      <AssetAiButton label={t('ai.action.explain')} question={assetQuestion(a)} />
                    </li>
                  ))}
                </ul>
              ) : (
                <Muted>{t('assetIntel.empty.new')}</Muted>
              )}
            </Panel>

            {/* Recommendations */}
            <Panel title={t('assetIntel.section.recommendations')}>
              {data.recent_recommendations.length === 0 ? (
                <Muted>{t('assetIntel.empty.recommendations')}</Muted>
              ) : (
                <ul className="space-y-3">
                  {data.recent_recommendations.map((rec, i) => (
                    <li key={i} className="text-sm">
                      <div className="flex items-center gap-2">
                        <span className={`text-xs font-semibold uppercase ${SEVERITY_CLASS[rec.severity] ?? 'text-white/60'}`}>{rec.severity}</span>
                        <span className="font-medium text-white/90">{rec.title}</span>
                      </div>
                      <p className="mt-0.5 text-xs text-white/60">{rec.rationale}</p>
                    </li>
                  ))}
                </ul>
              )}
            </Panel>

            {/* Recent AI investigations */}
            <Panel title={t('assetIntel.section.investigations')}>
              {data.recent_ai_investigations.length === 0 ? (
                <Muted>{t('assetIntel.empty.investigations')}</Muted>
              ) : (
                <ul className="space-y-2">
                  {data.recent_ai_investigations.map((inv, i) => (
                    <li key={i} className="flex items-center justify-between text-sm">
                      <span className="text-white/80">{inv.action.replace(/_/g, ' ')}</span>
                      <span className="text-xs text-white/40">{inv.at ? new Date(inv.at).toLocaleString() : ''}</span>
                    </li>
                  ))}
                </ul>
              )}
            </Panel>
          </div>

          <p className="text-xs text-white/40">{t('assetIntel.licenseNote')}</p>
        </>
      ) : null}

      <AiCopilotDrawer
        open={copilotOpen}
        onClose={() => setCopilotOpen(false)}
        workspaceUuid={workspaceUuid}
        copilot={assetIntelligenceService.copilot}
        title={t('assetIntel.copilot.title')}
      />
    </div>
  )
}

function Panel({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5">
      <h2 className="mb-3 text-sm font-semibold text-white">{title}</h2>
      {children}
    </div>
  )
}

function Muted({ children }: { children: ReactNode }) {
  return <p className="text-xs text-white/40">{children}</p>
}
