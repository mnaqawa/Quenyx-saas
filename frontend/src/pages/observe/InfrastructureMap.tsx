import { useState, useMemo, useRef } from 'react'
import { Link } from 'react-router-dom'
import { useWorkspaceContext } from '../../workspaces/WorkspaceContext'
import { useObserveMapHosts } from '../../hooks/useObserveData'
import { useObserveServices } from '../../hooks/useObserveData'
import { PageHeader } from '../../components/observe/PageHeader'

const VIEW_OPTIONS = ['Logical View', 'Physical View', 'Geographic View', 'Security Zones'] as const
const LAYER_OPTIONS = ['All Layers', 'Network Layer', 'Compute Layer', 'Storage Layer', 'Security Layer'] as const
const ZOOM_OPTIONS = ['Fit to Screen', '50%', '100%', '150%', '200%'] as const

function statusLabel(s: string): string {
  switch (s) {
    case 'ok':
      return 'Online'
    case 'warning':
      return 'Warning'
    case 'critical':
    case 'unreachable':
      return 'Critical'
    case 'unknown':
      return 'Degraded'
    case 'pending':
    default:
      return 'Pending'
  }
}

export default function InfrastructureMap() {
  const { selectedWorkspaceId } = useWorkspaceContext()
  const [activeTab, setActiveTab] = useState<'topology' | 'devices' | 'connections' | 'health'>('topology')
  const [viewType, setViewType] = useState<string>(VIEW_OPTIONS[0])
  const [layerFilter, setLayerFilter] = useState<string>(LAYER_OPTIONS[0])
  const [zoomLevel, setZoomLevel] = useState<string>(ZOOM_OPTIONS[0])
  const [designLevel, setDesignLevel] = useState<'hld' | 'lld'>('lld')
  const [isFullScreen, setIsFullScreen] = useState(false)
  const mapContainerRef = useRef<HTMLDivElement>(null)

  const { hosts, loading } = useObserveMapHosts(selectedWorkspaceId)
  const { data: servicesData } = useObserveServices({ workspaceId: selectedWorkspaceId ?? null, limit: 500 })

  const hostStatusCounts = useMemo(() => {
    const ok = hosts.filter((h) => h.status === 'ok').length
    const warning = hosts.filter((h) => h.status === 'warning').length
    const critical = hosts.filter((h) => h.status === 'critical' || h.status === 'unreachable').length
    const other = hosts.length - ok - warning - critical
    return { ok, warning, critical, other, total: hosts.length }
  }, [hosts])

  const criticalIssues = useMemo(() => {
    const issues: Array<{ id: string; label: string; severity: 'critical' | 'warning' }> = []
    hosts.forEach((h) => {
      if (h.status === 'critical' || h.status === 'unreachable') {
        issues.push({ id: h.name, label: `${h.name} host down or unreachable`, severity: 'critical' })
      } else if (h.status === 'warning') {
        issues.push({ id: h.name, label: `Service issues on ${h.name}`, severity: 'warning' })
      }
    })
    if (servicesData?.items) {
      const criticalSvcs = servicesData.items.filter((s: { status: string }) => s.status === 'critical')
      criticalSvcs.slice(0, 5).forEach((s: { host: string; service: string }) => {
        if (!issues.some((i) => i.label.includes(s.service)))) {
          issues.push({
            id: `${s.host}-${s.service}`,
            label: `${s.service} on ${s.host.replace(/^ws\d+-/, '')} critical`,
            severity: 'critical',
          })
        }
      })
    }
    return issues.slice(0, 10)
  }, [hosts, servicesData?.items])

  const connections = useMemo(() => {
    return hosts.map((h) => ({
      id: `mon-${h.name}`,
      source: h.name,
      type: 'monitored',
      destination: 'Monitoring',
      status: h.status === 'ok' ? 'Online' : h.status === 'warning' ? 'Warning' : 'Critical',
      speed: '—',
    }))
  }, [hosts])

  const zoomScale = useMemo(() => {
    const v = zoomLevel.replace('%', '')
    if (v === 'Fit to Screen') return 1
    const n = parseInt(v, 10)
    return Number.isNaN(n) ? 1 : n / 100
  }, [zoomLevel])

  const handleExportMap = () => {
    const payload = {
      version: '1.0',
      type: designLevel === 'hld' ? 'HLD' : 'LLD',
      generatedAt: new Date().toISOString(),
      view: viewType,
      layer: layerFilter,
      definitions: {
        'Compute Layer': 'Hosts and servers monitored by ShieldObserve',
        'Network Layer': 'Logical network segments (from host addressing)',
        'Storage Layer': 'Storage resources (define in Monitored Targets)',
        'Security Layer': 'Security zones and policies (define in Monitored Targets)',
      },
      zone: selectedWorkspaceId ? { id: selectedWorkspaceId, name: 'Workspace' } : null,
      nodes: hosts.map((h) => ({
        id: h.name,
        name: h.name,
        type: 'host',
        address: h.address,
        status: h.status,
        layer: 'Compute',
      })),
      connections: connections.map((c) => ({
        source: c.source,
        destination: c.destination,
        type: c.type,
        status: c.status,
      })),
    }
    const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `infrastructure-${designLevel}-${new Date().toISOString().slice(0, 10)}.json`
    a.click()
    URL.revokeObjectURL(url)
  }

  const handleFullScreen = () => {
    if (!mapContainerRef.current) return
    if (!document.fullscreenElement) {
      mapContainerRef.current.requestFullscreen?.().then(() => setIsFullScreen(true)).catch(() => {})
    } else {
      document.exitFullscreen?.().then(() => setIsFullScreen(false)).catch(() => {})
    }
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center py-24">
        <div className="text-sm text-white/60">Loading infrastructure...</div>
      </div>
    )
  }

  const tabs = [
    { id: 'topology' as const, label: 'Network Topology' },
    { id: 'devices' as const, label: 'Device List' },
    { id: 'connections' as const, label: 'Connections' },
    { id: 'health' as const, label: 'Health Overview' },
  ]

  return (
    <div className="space-y-4" ref={mapContainerRef}>
      <PageHeader
        title="Infrastructure Map"
        subtitle="Visual network topology and infrastructure overview."
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <button
              type="button"
              onClick={handleExportMap}
              className="inline-flex items-center gap-2 rounded-lg border border-white/20 bg-white/10 px-4 py-1.5 text-xs font-medium text-white hover:bg-white/20"
            >
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                <polyline points="7 10 12 15 17 10" />
                <line x1="12" y1="15" x2="12" y2="3" />
              </svg>
              Export Map
            </button>
            <button
              type="button"
              onClick={handleFullScreen}
              className="inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white/80 hover:bg-white/10"
            >
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3" />
              </svg>
              Full Screen
            </button>
            <Link
              to={selectedWorkspaceId ? `/app/workspaces/${selectedWorkspaceId}/observe/targets` : '#'}
              className="inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white/80 hover:bg-white/10"
              title="Configure hosts and services in Monitored Targets"
            >
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <circle cx="12" cy="12" r="3" />
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z" />
              </svg>
              Configure
            </Link>
          </div>
        }
      />

      {/* View and layer filters */}
      <div className="flex flex-wrap items-center gap-3">
        <select
          value={viewType}
          onChange={(e) => setViewType(e.target.value)}
          className="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white focus:border-sky-500/50 focus:outline-none focus:ring-1 focus:ring-sky-500/50"
        >
          {VIEW_OPTIONS.map((o) => (
            <option key={o} value={o} className="bg-slate-900 text-white">
              {o}
            </option>
          ))}
        </select>
        <select
          value={layerFilter}
          onChange={(e) => setLayerFilter(e.target.value)}
          className="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white focus:border-sky-500/50 focus:outline-none focus:ring-1 focus:ring-sky-500/50"
        >
          {LAYER_OPTIONS.map((o) => (
            <option key={o} value={o} className="bg-slate-900 text-white">
              {o}
            </option>
          ))}
        </select>
        <select
          value={zoomLevel}
          onChange={(e) => setZoomLevel(e.target.value)}
          className="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white focus:border-sky-500/50 focus:outline-none focus:ring-1 focus:ring-sky-500/50"
        >
          {ZOOM_OPTIONS.map((o) => (
            <option key={o} value={o} className="bg-slate-900 text-white">
              {o}
            </option>
          ))}
        </select>
      </div>

      {/* Tabs */}
      <div className="border-b border-white/10">
        <nav className="flex gap-1">
          {tabs.map((tab) => (
            <button
              key={tab.id}
              type="button"
              onClick={() => setActiveTab(tab.id)}
              className={`border-b-2 px-4 py-2 text-sm font-medium transition-colors ${
                activeTab === tab.id
                  ? 'border-sky-500 text-sky-200'
                  : 'border-transparent text-white/60 hover:text-white/80'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </nav>
      </div>

      {/* Tab content */}
      <div className="min-h-[400px] rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
        {activeTab === 'topology' && (
          <>
            <div className="mb-4 flex items-center justify-between">
              <div>
                <h3 className="text-sm font-semibold">Network Topology Map</h3>
                <p className="text-xs text-white/50">Interactive infrastructure visualization (real data from Monitored Targets)</p>
              </div>
              <div className="flex items-center gap-2">
                <span className="text-xs text-white/50">Design:</span>
                <button
                  type="button"
                  onClick={() => setDesignLevel('hld')}
                  className={`rounded px-2 py-1 text-xs ${designLevel === 'hld' ? 'bg-sky-500/30 text-sky-200' : 'bg-white/5 text-white/60 hover:bg-white/10'}`}
                >
                  HLD
                </button>
                <button
                  type="button"
                  onClick={() => setDesignLevel('lld')}
                  className={`rounded px-2 py-1 text-xs ${designLevel === 'lld' ? 'bg-sky-500/30 text-sky-200' : 'bg-white/5 text-white/60 hover:bg-white/10'}`}
                >
                  LLD
                </button>
              </div>
            </div>
            <div
              className="relative rounded-lg border border-white/10 bg-white/5 p-6"
              style={{ transform: zoomLevel !== 'Fit to Screen' ? `scale(${zoomScale})` : undefined, transformOrigin: 'top left' }}
            >
              {hosts.length === 0 ? (
                <div className="py-12 text-center text-sm text-white/50">
                  No hosts. Add hosts in <Link to={selectedWorkspaceId ? `/app/workspaces/${selectedWorkspaceId}/observe/targets` : '#'} className="text-sky-300 hover:underline">Monitored Targets</Link>.
                </div>
              ) : designLevel === 'hld' ? (
                <div className="flex flex-col items-center gap-4">
                  <div className="rounded-xl border-2 border-sky-500/30 bg-sky-500/10 px-8 py-6 text-center">
                    <p className="text-xs font-medium text-sky-200/80 uppercase tracking-wider">Workspace Zone</p>
                    <p className="mt-1 text-2xl font-bold">{hostStatusCounts.total} hosts</p>
                    <p className="mt-1 text-xs text-white/60">{hostStatusCounts.ok} healthy, {hostStatusCounts.warning} warning, {hostStatusCounts.critical} critical</p>
                  </div>
                  <p className="text-xs text-white/40">HLD: High-level view. Export Map for full HLD/LLD JSON with layer definitions.</p>
                </div>
              ) : (
                <div className="flex flex-wrap items-center justify-center gap-6">
                  <div className="rounded-xl border border-white/20 bg-white/5 px-4 py-3 text-center">
                    <p className="text-[10px] font-medium text-white/50 uppercase">Monitoring</p>
                    <p className="text-xs font-semibold text-sky-200">ShieldObserve</p>
                  </div>
                  {hosts.map((h) => (
                    <div
                      key={h.name}
                      className={`rounded-xl border-2 px-4 py-3 text-center min-w-[120px] ${
                        h.status === 'ok'
                          ? 'border-emerald-500/40 bg-emerald-500/10'
                          : h.status === 'warning'
                            ? 'border-amber-500/40 bg-amber-500/10'
                            : 'border-rose-500/40 bg-rose-500/10'
                      }`}
                    >
                      <p className="text-xs font-semibold truncate max-w-[140px]" title={h.name}>{h.name}</p>
                      <p className="mt-0.5 text-[10px] text-white/60 truncate max-w-[140px]" title={h.address}>{h.address}</p>
                      <p className={`mt-1 text-[10px] font-medium ${
                        h.status === 'ok' ? 'text-emerald-400' : h.status === 'warning' ? 'text-amber-400' : 'text-rose-400'
                      }`}>
                        {statusLabel(h.status)}
                      </p>
                    </div>
                  ))}
                </div>
              )}
            </div>
            <div className="mt-4 flex gap-6 text-[10px] text-white/50">
              <span className="flex items-center gap-1.5"><span className="h-px w-6 bg-emerald-500/60" /> Healthy</span>
              <span className="flex items-center gap-1.5"><span className="h-px w-6 bg-amber-500/60" /> Warning</span>
              <span className="flex items-center gap-1.5"><span className="h-px w-6 bg-rose-500/60" /> Critical</span>
            </div>
          </>
        )}

        {activeTab === 'devices' && (
          <>
            <h3 className="mb-1 text-sm font-semibold">Device Inventory</h3>
            <p className="mb-4 text-xs text-white/50">All devices (hosts) in the infrastructure — real data from Monitored Targets.</p>
            {hosts.length === 0 ? (
              <div className="py-12 text-center text-sm text-white/50">No devices. Add hosts in Monitored Targets.</div>
            ) : (
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {hosts.map((h) => (
                  <div key={h.name} className="rounded-xl border border-white/10 bg-white/5 p-4">
                    <div className="flex items-start justify-between">
                      <div className="min-w-0 flex-1">
                        <p className="font-semibold text-sm truncate" title={h.name}>{h.name}</p>
                        <p className="mt-1 text-xs text-white/60">server · {h.address}</p>
                        <p className="mt-1 text-xs text-white/50">Network: default</p>
                      </div>
                      <span className={`shrink-0 rounded-full px-2 py-1 text-[10px] font-medium ${
                        h.status === 'ok' ? 'bg-emerald-500/20 text-emerald-200' :
                        h.status === 'warning' ? 'bg-amber-500/20 text-amber-200' :
                        'bg-rose-500/20 text-rose-200'
                      }`}>
                        {statusLabel(h.status)}
                      </span>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </>
        )}

        {activeTab === 'connections' && (
          <>
            <h3 className="mb-1 text-sm font-semibold">Network Connections</h3>
            <p className="mb-4 text-xs text-white/50">Logical links from hosts to monitoring — real data.</p>
            {connections.length === 0 ? (
              <div className="py-12 text-center text-sm text-white/50">No connections. Add hosts in Monitored Targets.</div>
            ) : (
              <div className="space-y-3">
                {connections.map((c) => (
                  <div key={c.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-white/10 bg-white/5 px-4 py-3">
                    <span className="font-mono text-xs">{c.source}</span>
                    <span className="text-white/40">— {c.type} —</span>
                    <span className="font-mono text-xs">{c.destination}</span>
                    <span className={`text-xs font-medium ${
                      c.status === 'Online' ? 'text-emerald-400' : c.status === 'Warning' ? 'text-amber-400' : 'text-rose-400'
                    }`}>
                      {c.status}
                    </span>
                    <span className="text-xs text-white/50">{c.speed}</span>
                  </div>
                ))}
              </div>
            )}
          </>
        )}

        {activeTab === 'health' && (
          <div className="grid gap-6 md:grid-cols-3">
            <div>
              <h3 className="mb-3 text-sm font-semibold">Infrastructure Health</h3>
              <div className="space-y-2">
                <div className="flex justify-between rounded-lg bg-white/5 px-3 py-2">
                  <span className="text-xs text-white/70">Hosts</span>
                  <span className="text-xs font-medium text-emerald-400">{hostStatusCounts.ok}/{hostStatusCounts.total} Healthy</span>
                </div>
                <div className="flex justify-between rounded-lg bg-white/5 px-3 py-2">
                  <span className="text-xs text-white/70">Connections</span>
                  <span className={`text-xs font-medium ${connections.filter((c) => c.status === 'Online').length === connections.length ? 'text-emerald-400' : 'text-amber-400'}`}>
                    {connections.filter((c) => c.status === 'Online').length}/{connections.length} Active
                  </span>
                </div>
              </div>
            </div>
            <div>
              <h3 className="mb-3 text-sm font-semibold">Critical Issues</h3>
              {criticalIssues.length === 0 ? (
                <p className="text-xs text-white/50">No critical issues.</p>
              ) : (
                <ul className="space-y-2">
                  {criticalIssues.map((i) => (
                    <li key={i.id} className="flex items-center gap-2 text-xs">
                      {i.severity === 'critical' ? (
                        <span className="text-rose-400" aria-hidden>●</span>
                      ) : (
                        <span className="text-amber-400" aria-hidden>▲</span>
                      )}
                      <span className={i.severity === 'critical' ? 'text-rose-200' : 'text-amber-200'}>{i.label}</span>
                    </li>
                  ))}
                </ul>
              )}
            </div>
            <div>
              <h3 className="mb-3 text-sm font-semibold">Quick Actions</h3>
              <div className="flex flex-col gap-2">
                <Link
                  to={selectedWorkspaceId ? `/app/workspaces/${selectedWorkspaceId}/observe/targets` : '#'}
                  className="inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-left text-xs text-white/80 hover:bg-white/10"
                >
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <circle cx="12" cy="12" r="3" />
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z" />
                  </svg>
                  Monitored Targets
                </Link>
                <button type="button" onClick={handleExportMap} className="inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-left text-xs text-white/80 hover:bg-white/10">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                    <polyline points="7 10 12 15 17 10" />
                    <line x1="12" y1="15" x2="12" y2="3" />
                  </svg>
                  Export Topology (HLD/LLD)
                </button>
              </div>
            </div>
          </div>
        )}
      </div>

      {/* Layer definitions (always visible for reference) */}
      <details className="rounded-2xl border border-white/10 bg-[#0f151d] p-4 text-white">
        <summary className="cursor-pointer text-xs font-semibold text-white/70 uppercase tracking-wider">Layer definitions</summary>
        <dl className="mt-3 space-y-2 text-xs text-white/60">
          <div><dt className="font-medium text-white/80">Compute Layer</dt><dd>Hosts and servers monitored by ShieldObserve (real data from Monitored Targets).</dd></div>
          <div><dt className="font-medium text-white/80">Network Layer</dt><dd>Logical network segments; derived from host addressing. Add network metadata in Monitored Targets for richer LLD.</dd></div>
          <div><dt className="font-medium text-white/80">Storage Layer</dt><dd>Storage resources. Define in Monitored Targets or integrate with storage monitoring for live data.</dd></div>
          <div><dt className="font-medium text-white/80">Security Layer</dt><dd>Security zones and policies. Define in Monitored Targets or link to security tools for live data.</dd></div>
        </dl>
      </details>
    </div>
  )
}
