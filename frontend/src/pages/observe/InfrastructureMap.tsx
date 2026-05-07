/**
 * Infrastructure Map — Observe module (QynSight microservice).
 * All dynamic data (hosts, services, connections, status) comes from Observe APIs only; no fixtures or hardcoded variables.
 */
import { useState, useMemo, useRef, useEffect, useCallback } from 'react'
import { Link } from 'react-router-dom'
import html2canvas from 'html2canvas'
import { jsPDF } from 'jspdf'
import { useWorkspaceContext } from '../../workspaces/WorkspaceContext'
import { useObserveMapHosts, useObserveServices, useObserveConnections, useObservePortScans } from '../../hooks/useObserveData'
import { observeService } from '../../services/observeService'
import { PageHeader } from '../../components/observe/PageHeader'
import type { PortScanResult } from '../../types/observe'

type HostRow = { name: string; address: string; status: string }

const VIEW_OPTIONS = ['Logical View', 'Physical View', 'By Zone', 'Security Zones'] as const
const LAYER_OPTIONS = ['All Layers', 'Network Layer', 'Compute Layer', 'Storage Layer', 'Security Layer'] as const
const ZOOM_OPTIONS = ['Fit to Screen', '50%', '100%', '150%', '200%'] as const

// Zone presets (UI labels only; dynamic data comes from Observe microservice)
export const ZONE_OPTIONS = ['Unassigned', 'DMZ', 'WebApp', 'DB', 'Internal', 'Edge', 'API', 'Cache'] as const
export type ZoneId = (typeof ZONE_OPTIONS)[number] | string

const DIAGRAM_STORAGE_KEY = 'quenyx-infra-diagram'

export interface DiagramState {
  hostZones: Record<string, ZoneId>
  nodePositions: Record<string, { x: number; y: number }>
  customZones: string[]
}

function loadDiagram(workspaceId: string | null): DiagramState {
  if (!workspaceId) return { hostZones: {}, nodePositions: {}, customZones: [] }
  try {
    const raw = localStorage.getItem(`${DIAGRAM_STORAGE_KEY}-${workspaceId}`)
    if (!raw) return { hostZones: {}, nodePositions: {}, customZones: [] }
    const parsed = JSON.parse(raw) as DiagramState
    return {
      hostZones: parsed.hostZones ?? {},
      nodePositions: parsed.nodePositions ?? {},
      customZones: Array.isArray(parsed.customZones) ? parsed.customZones : [],
    }
  } catch {
    return { hostZones: {}, nodePositions: {}, customZones: [] }
  }
}

function saveDiagram(workspaceId: string | null, state: DiagramState) {
  if (!workspaceId) return
  try {
    localStorage.setItem(`${DIAGRAM_STORAGE_KEY}-${workspaceId}`, JSON.stringify(state))
  } catch {
    // ignore
  }
}

function getAllZones(customZones: string[]): string[] {
  return [...ZONE_OPTIONS.filter((z) => z !== 'Unassigned'), ...customZones]
}

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

/** Tooltip explaining Pending: first check not run yet */
function statusTooltip(s: string): string | undefined {
  if (s?.toLowerCase() === 'pending') {
    return 'Pending = first check not run yet. QynSight runs checks every minute. Add services in Monitored Targets for more checks.'
  }
  return undefined
}

// Zone colors for enterprise-style topology (similar to Thara network diagram)
const ZONE_STYLES: Record<string, { border: string; bg: string; header: string }> = {
  DMZ: { border: 'border-amber-500/50', bg: 'bg-amber-500/5', header: 'bg-amber-500/20 text-amber-200' },
  WebApp: { border: 'border-sky-500/50', bg: 'bg-sky-500/5', header: 'bg-sky-500/20 text-sky-200' },
  DB: { border: 'border-violet-500/50', bg: 'bg-violet-500/5', header: 'bg-violet-500/20 text-violet-200' },
  Internal: { border: 'border-emerald-500/50', bg: 'bg-emerald-500/5', header: 'bg-emerald-500/20 text-emerald-200' },
  Edge: { border: 'border-cyan-500/50', bg: 'bg-cyan-500/5', header: 'bg-cyan-500/20 text-cyan-200' },
  API: { border: 'border-indigo-500/50', bg: 'bg-indigo-500/5', header: 'bg-indigo-500/20 text-indigo-200' },
  Cache: { border: 'border-orange-500/50', bg: 'bg-orange-500/5', header: 'bg-orange-500/20 text-orange-200' },
  Unassigned: { border: 'border-white/20', bg: 'bg-white/5', header: 'bg-white/10 text-white/70' },
}

function getZoneStyle(zone: string) {
  return ZONE_STYLES[zone] ?? ZONE_STYLES.Unassigned
}

function ZoneBasedTopology({
  hostsByZone,
  zoneFilter,
  statusLabel,
  allZonesList,
  layerFiltered,
  portScansByHost,
}: {
  hostsByZone: Map<string, HostRow[]>
  zoneFilter: string
  statusLabel: (s: string) => string
  allZonesList: string[]
  layerFiltered: { hostsFiltered: HostRow[]; networksFiltered: Array<{ id: string; name: string; hosts: HostRow[]; status: string }> }
  portScansByHost: Map<string, PortScanResult>
}) {
  const zonesToShow = zoneFilter === 'All Zones'
    ? allZonesList.filter((z) => (hostsByZone.get(z) ?? []).length > 0)
    : [zoneFilter]
  const hasNetworks = layerFiltered.networksFiltered.length > 0

  return (
    <div className="relative space-y-6">
      <p className="text-xs text-white/50 mb-3">
        Zone-based topology — assign zones in Device List. Layout inspired by enterprise network diagrams (DMZ → Web/App → DB).
      </p>
      {/* Monitoring node (top-left, like QynSight in PDF) */}
      <div className="absolute right-4 top-4 z-10 rounded-xl border-2 border-sky-500/50 bg-sky-500/10 px-4 py-2 shadow-lg">
        <p className="text-[10px] font-medium text-sky-300/80 uppercase tracking-wider">Monitoring</p>
        <p className="text-sm font-semibold text-sky-200">QynSight</p>
      </div>
      {/* Zone containers in a flow layout */}
      <div className="grid grid-cols-1 gap-6 pt-14 md:grid-cols-2 lg:grid-cols-3">
        {zonesToShow.map((zoneName) => {
          const hosts = hostsByZone.get(zoneName) ?? []
          const style = getZoneStyle(zoneName)
          return (
            <div
              key={zoneName}
              className={`rounded-xl border-2 ${style.border} ${style.bg} p-0 overflow-hidden min-h-[140px]`}
            >
              <div className={`px-4 py-2 ${style.header} border-b border-white/10`}>
                <p className="text-xs font-semibold uppercase tracking-wider">{zoneName} Zone</p>
                <p className="text-[10px] opacity-80">{hosts.length} host{hosts.length !== 1 ? 's' : ''}</p>
              </div>
              <div className="flex flex-wrap gap-3 p-4">
                {hosts.map((h) => (
                  <div
                    key={h.name}
                    className={`rounded-lg border-2 px-3 py-2.5 text-center min-w-[110px] transition-shadow hover:shadow-md ${
                      h.status === 'ok' ? 'border-emerald-500/40 bg-emerald-500/10' : h.status === 'warning' ? 'border-amber-500/40 bg-amber-500/10' : 'border-rose-500/40 bg-rose-500/10'
                    }`}
                  >
                    <div className="flex items-center justify-center gap-1.5 mb-0.5">
                      <span className={`h-2 w-2 rounded-full ${h.status === 'ok' ? 'bg-emerald-400' : h.status === 'warning' ? 'bg-amber-400' : 'bg-rose-400'}`} />
                      <p className="text-xs font-semibold truncate max-w-[100px]" title={h.name}>{h.name}</p>
                    </div>
                    <p className="text-[10px] text-white/60 truncate max-w-[100px]" title={h.address}>{h.address || '—'}</p>
                    <p className={`mt-1 text-[10px] font-medium ${h.status === 'ok' ? 'text-emerald-400' : h.status === 'warning' ? 'text-amber-400' : 'text-rose-400'}`}>
                      {statusLabel(h.status)}
                    </p>
                    {portScansByHost.get(h.name)?.scan?.status === 'completed' && (
                      <p className="mt-1 text-[9px] text-sky-400">
                        {portScansByHost.get(h.name)!.scan!.open_ports_count ?? 0} open ports
                      </p>
                    )}
                  </div>
                ))}
              </div>
            </div>
          )
        })}
      </div>
      {/* Network segments (when Network Layer is shown) */}
      {hasNetworks && (
        <div className="rounded-xl border-2 border-sky-500/30 bg-sky-500/5 p-4">
          <p className="text-xs font-semibold text-sky-200 uppercase tracking-wider mb-3">Network Segments</p>
          <div className="flex flex-wrap gap-3">
            {layerFiltered.networksFiltered.map((n) => (
              <div
                key={n.id}
                className="rounded-lg border-2 border-sky-500/40 bg-sky-500/10 px-4 py-2.5 min-w-[120px]"
              >
                <p className="text-xs font-semibold truncate max-w-[140px]" title={n.name}>{n.name}</p>
                <p className="text-[10px] text-white/60">{n.hosts.length} host{n.hosts.length !== 1 ? 's' : ''}</p>
                <p className={`mt-1 text-[10px] font-medium ${n.status === 'ok' ? 'text-sky-400' : n.status === 'warning' ? 'text-amber-400' : 'text-rose-400'}`}>
                  {statusLabel(n.status)}
                </p>
              </div>
            ))}
          </div>
        </div>
      )}
      {zonesToShow.length === 0 && !hasNetworks && (
        <div className="py-12 text-center text-sm text-white/50">
          No zones with hosts. Assign zones in Device List or add hosts in Monitored Targets.
        </div>
      )}
    </div>
  )
}

// QynSight cloud: centered at top, above networks (like PDF) — larger and clearer
const CLOUD_NODE = { cx: 320, cy: 48, r: 72 }

function LLDTopology({
  networks,
  diagram,
  draggingNode,
  handleNodeMouseDown,
  statusLabel,
  portScansByHost,
}: {
  networks: Array<{ id: string; name: string; hosts: HostRow[]; status: string }>
  diagram: DiagramState
  draggingNode: string | null
  handleNodeMouseDown: (e: React.MouseEvent, name: string) => void
  statusLabel: (s: string) => string
  portScansByHost: Map<string, PortScanResult>
}) {
  const nodeW = 130
  const nodeH = 58
  const containerPad = 24
  const networkGap = 20
  const hostGap = 12

  // Layout: networks as containers with hosts inside. Cloud at top.
  const normalizedLayouts = networks.map((n, netIdx) => {
    const hostsInNet = n.hosts
    const rows = Math.ceil(hostsInNet.length / 3) || 1
    const innerW = Math.max(200, Math.min(3, hostsInNet.length) * (nodeW + hostGap) - hostGap)
    const innerH = rows * nodeH + (rows - 1) * hostGap
    const w = innerW + containerPad * 2
    const h = 52 + innerH + containerPad * 2
    const x = 40 + netIdx * (w + networkGap)
    const y = 100
    return { network: n, x, y, w, h, hostPositions: hostsInNet }
  })

  const cloudCx = CLOUD_NODE.cx
  const cloudCy = CLOUD_NODE.cy

  return (
    <div className="relative min-h-[500px] w-full" style={{ minWidth: 680 }}>
      {/* Connection lines: Cloud -> each network container */}
      <svg className="absolute inset-0 pointer-events-none z-0" width="100%" height="100%">
        <defs>
          <marker id="arrowhead" markerWidth="6" markerHeight="4" refX="5" refY="2" orient="auto">
            <polygon points="0 0, 6 2, 0 4" fill="rgba(14,165,233,0.5)" />
          </marker>
        </defs>
        {normalizedLayouts.map((layout) => {
          const netCenterX = layout.x + layout.w / 2
          const netTopY = layout.y
          const stroke = layout.network.status === 'ok' ? '#10b981' : layout.network.status === 'warning' ? '#f59e0b' : '#f43f5e'
          return (
            <line
              key={`line-${layout.network.id}`}
              x1={cloudCx}
              y1={cloudCy + CLOUD_NODE.r}
              x2={netCenterX}
              y2={netTopY}
              stroke={stroke}
              strokeWidth="1.5"
              strokeDasharray="4 3"
              opacity={0.6}
              markerEnd="url(#arrowhead)"
            />
          )
        })}
      </svg>

      {/* QynSight as cloud (top center) — larger, clearer diagram */}
      <div
        className="absolute z-20 flex flex-col items-center justify-center rounded-2xl border-2 border-sky-500/40 bg-sky-500/15 px-6 py-4 shadow-xl backdrop-blur-sm"
        style={{ left: cloudCx - CLOUD_NODE.r - 24, top: 4, width: (CLOUD_NODE.r + 24) * 2, minHeight: CLOUD_NODE.r * 1.4 }}
      >
        <div className="flex items-center gap-3">
          <svg viewBox="0 0 80 48" className="w-14 h-9 text-sky-400 shrink-0" fill="currentColor" aria-hidden>
            <path d="M12 32c-6 0-12-5-12-11s5-11 12-11c2 0 4 0 5 1-2-6 3-11 8-11 3 0 5 1 7 4 3-2 6-3 10-3 8 0 14 6 14 14 0 2 0 3 0 3H12z" />
            <path d="M48 32c-6 0-12-5-12-11s5-11 12-11c2 0 4 0 5 1-2-6 3-11 8-11 3 0 5 1 7 4 3-2 6-3 10-3 8 0 14 6 14 14 0 2 0 3 0 3H48z" opacity="0.85" />
          </svg>
          <div className="text-left">
            <p className="text-[11px] font-semibold text-sky-300/95 uppercase tracking-widest">Monitoring</p>
            <p className="text-lg font-bold text-sky-100 tracking-tight">QynSight</p>
          </div>
        </div>
      </div>

      {/* Network containers with servers inside (like PDF) */}
      {normalizedLayouts.map((layout) => (
        <div
          key={layout.network.id}
          className={`absolute z-10 rounded-xl border-2 overflow-visible ${
            layout.network.status === 'ok' ? 'border-sky-500/50 bg-sky-500/5' : layout.network.status === 'warning' ? 'border-amber-500/50 bg-amber-500/5' : 'border-rose-500/50 bg-rose-500/5'
          }`}
          style={{ left: layout.x, top: layout.y, width: layout.w, minHeight: layout.h }}
        >
          <div className="px-3 py-2 border-b border-white/10 flex items-center justify-between">
            <p className="text-[10px] font-medium text-sky-300/80 uppercase tracking-wider">Network</p>
            <p className="text-xs font-semibold truncate max-w-[180px]" title={layout.network.name}>{layout.network.name}</p>
            <span className={`text-[10px] font-medium ${layout.network.status === 'ok' ? 'text-sky-400' : layout.network.status === 'warning' ? 'text-amber-400' : 'text-rose-400'}`}>
              {statusLabel(layout.network.status)} · {layout.network.hosts.length} host{layout.network.hosts.length !== 1 ? 's' : ''}
            </span>
          </div>
          <div className="p-3 flex flex-wrap gap-3">
            {layout.network.hosts.map((h) => (
              <div
                key={h.name}
                className={`rounded-lg border-2 px-3 py-2.5 text-center cursor-move shrink-0 transition-shadow hover:shadow-lg ${
                  draggingNode === h.name ? 'ring-2 ring-sky-400 ring-offset-2 ring-offset-[#0f151d] z-20' : ''
                } ${h.status === 'ok' ? 'border-emerald-500/40 bg-emerald-500/10' : h.status === 'warning' ? 'border-amber-500/40 bg-amber-500/10' : 'border-rose-500/40 bg-rose-500/10'}`}
                style={{ width: nodeW, minHeight: nodeH }}
                onMouseDown={(e) => handleNodeMouseDown(e, h.name)}
              >
                <div className="flex items-center gap-1.5 justify-center mb-0.5">
                  <span className={`h-2 w-2 rounded-full shrink-0 ${h.status === 'ok' ? 'bg-emerald-400' : h.status === 'warning' ? 'bg-amber-400' : 'bg-rose-400'}`} />
                  <p className="text-xs font-semibold truncate max-w-[90px]" title={h.name}>{h.name}</p>
                </div>
                <p className="text-[10px] text-white/60 truncate max-w-[110px]" title={h.address}>{h.address || '—'}</p>
                <p
                  className={`mt-1 text-[10px] font-medium ${h.status === 'ok' ? 'text-emerald-400' : h.status === 'warning' ? 'text-amber-400' : 'text-rose-400'}`}
                  title={statusTooltip(h.status)}
                >
                  {statusLabel(h.status)}
                </p>
                {diagram.hostZones[h.name] && diagram.hostZones[h.name] !== 'Unassigned' && (
                  <p className="mt-0.5 text-[9px] text-white/50">Zone: {String(diagram.hostZones[h.name])}</p>
                )}
                {portScansByHost.get(h.name)?.scan?.status === 'completed' && (
                  <p className="mt-0.5 text-[9px] text-sky-400">
                    {portScansByHost.get(h.name)!.scan!.open_ports_count ?? 0} open ports
                  </p>
                )}
              </div>
            ))}
          </div>
        </div>
      ))}
    </div>
  )
}

export default function InfrastructureMap() {
  const { selectedWorkspaceId } = useWorkspaceContext()
  const [activeTab, setActiveTab] = useState<'topology' | 'devices' | 'connections' | 'ports' | 'health'>('topology')
  const [viewType, setViewType] = useState<string>(VIEW_OPTIONS[0])
  const [layerFilter, setLayerFilter] = useState<string>(LAYER_OPTIONS[0])
  const [zoomLevel, setZoomLevel] = useState<string>(ZOOM_OPTIONS[0])
  const [designLevel, setDesignLevel] = useState<'hld' | 'lld'>('lld')
  const [zoneFilter, setZoneFilter] = useState<string>('All Zones')
  const [diagram, setDiagram] = useState<DiagramState>(() => loadDiagram(selectedWorkspaceId))
  const [customZoneInput, setCustomZoneInput] = useState('')
  const [draggingNode, setDraggingNode] = useState<string | null>(null)
  const [dragOffset, setDragOffset] = useState({ x: 0, y: 0 })
  const [autoRefresh, setAutoRefresh] = useState(false)
  const [autoRefreshSeconds, setAutoRefreshSeconds] = useState(30)
  const [scanModalOpen, setScanModalOpen] = useState(false)
  const [scanOptions, setScanOptions] = useState<{ ports: 'top100' | 'all' | 'range'; portsRange: string; protocol: 'tcp' | 'udp'; hostIds: number[] }>({
    ports: 'top100',
    portsRange: '1-1024',
    protocol: 'tcp',
    hostIds: [],
  })
  const [scanning, setScanning] = useState(false)
  const [scanError, setScanError] = useState<string | null>(null)
  const [scanStartedMessage, setScanStartedMessage] = useState<string | null>(null)
  const mapContainerRef = useRef<HTMLDivElement>(null)
  const topologyRef = useRef<HTMLDivElement>(null)

  const refetchIntervalMs = autoRefresh ? autoRefreshSeconds * 1000 : 0
  // Observe module: real data only from QynSight microservice APIs (no fixtures / no hardcoded dynamic data)
  const { hosts, loading } = useObserveMapHosts(selectedWorkspaceId, refetchIntervalMs, true)
  const { data: servicesData } = useObserveServices({
    workspaceId: selectedWorkspaceId ?? null,
    limit: 500,
    refetchIntervalMs,
    realDataOnly: true,
  })
  const { data: apiConnectionsData } = useObserveConnections(selectedWorkspaceId, {
    refetchIntervalMs,
    includeIntegrations: true,
  })
  const { data: portScansData, refresh: refreshPortScans } = useObservePortScans(selectedWorkspaceId, { refetchIntervalMs })
  const portScansByHost = useMemo(() => {
    const map = new Map<string, NonNullable<typeof portScansData>[number]>()
    ;(portScansData ?? []).forEach((ps) => map.set(ps.host_name, ps))
    return map
  }, [portScansData])
  const exportAreaRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    setDiagram(loadDiagram(selectedWorkspaceId))
  }, [selectedWorkspaceId])

  useEffect(() => {
    saveDiagram(selectedWorkspaceId, diagram)
  }, [selectedWorkspaceId, diagram])

  const allZonesList = useMemo(() => getAllZones(diagram.customZones), [diagram.customZones])

  const hostsByZone = useMemo(() => {
    const map = new Map<string, HostRow[]>()
    map.set('Unassigned', [])
    allZonesList.forEach((z) => map.set(z, []))
    hosts.forEach((h) => {
      const zone = diagram.hostZones[h.name] ?? 'Unassigned'
      if (!map.has(zone)) map.set(zone, [])
      map.get(zone)!.push(h)
    })
    if (zoneFilter !== 'All Zones') {
      const filtered = new Map<string, HostRow[]>()
      const list = map.get(zoneFilter) ?? []
      if (zoneFilter !== 'Unassigned') filtered.set(zoneFilter, list)
      else filtered.set('Unassigned', list)
      return filtered
    }
    return map
  }, [hosts, diagram.hostZones, allZonesList, zoneFilter])

  const hostsFilteredByZone = useMemo(() => {
    if (zoneFilter === 'All Zones') return hosts
    return hostsByZone.get(zoneFilter) ?? []
  }, [hosts, zoneFilter, hostsByZone])

  const setHostZone = useCallback((hostName: string, zone: ZoneId) => {
    setDiagram((prev) => ({ ...prev, hostZones: { ...prev.hostZones, [hostName]: zone } }))
  }, [])

  const addCustomZone = useCallback(() => {
    const name = customZoneInput.trim()
    if (!name || diagram.customZones.includes(name)) return
    setDiagram((prev) => ({ ...prev, customZones: [...prev.customZones, name] }))
    setCustomZoneInput('')
  }, [customZoneInput, diagram.customZones])

  const getNodePosition = useCallback((hostName: string, index: number) => {
    const saved = diagram.nodePositions[hostName]
    if (saved) return saved
    const row = Math.floor(index / 4)
    const col = index % 4
    return { x: 80 + col * 160, y: 60 + row * 100 }
  }, [diagram.nodePositions])

  const handleNodeMouseDown = useCallback((e: React.MouseEvent, hostName: string) => {
    e.preventDefault()
    const pos = diagram.nodePositions[hostName] ?? getNodePosition(hostName, 0)
    setDraggingNode(hostName)
    setDragOffset({ x: e.clientX - pos.x, y: e.clientY - pos.y })
  }, [diagram.nodePositions, getNodePosition])

  const handleMouseMove = useCallback((e: React.MouseEvent) => {
    if (!draggingNode) return
    setDiagram((prev) => ({
      ...prev,
      nodePositions: {
        ...prev.nodePositions,
        [draggingNode]: { x: e.clientX - dragOffset.x, y: e.clientY - dragOffset.y },
      },
    }))
  }, [draggingNode, dragOffset])

  const handleMouseUp = useCallback(() => {
    if (draggingNode) setDraggingNode(null)
  }, [draggingNode])

  const networks = useMemo(() => {
    const byKey = new Map<string, HostRow[]>()
    hosts.forEach((h) => {
      const addr = (h.address || '').trim()
      const parts = addr.split(/\./)
      const isIPv4 = parts.length === 4 && parts.every((p) => /^\d+$/.test(p))
      const key = isIPv4 ? `${parts[0]}.${parts[1]}.${parts[2]}.0/24` : (addr || 'default')
      if (!byKey.has(key)) byKey.set(key, [])
      byKey.get(key)!.push(h)
    })
    return Array.from(byKey.entries()).map(([key, hostList]) => ({
      id: key,
      name: key,
      hosts: hostList,
      status: hostList.every((h) => h.status === 'ok') ? 'ok' : hostList.some((h) => h.status === 'critical' || h.status === 'unreachable') ? 'critical' : 'warning',
    }))
  }, [hosts])

  const layerFiltered = useMemo(() => {
    const showCompute = layerFilter === 'All Layers' || layerFilter === 'Compute Layer'
    const showNetwork = layerFilter === 'All Layers' || layerFilter === 'Network Layer'
    const showStorage = layerFilter === 'Storage Layer'
    const showSecurity = layerFilter === 'Security Layer'
    return {
      showCompute,
      showNetwork,
      showStorage,
      showSecurity,
      hasData: (showCompute && hosts.length > 0) || (showNetwork && networks.length > 0),
      hostsFiltered: showCompute ? hosts : [] as HostRow[],
      networksFiltered: showNetwork ? networks : [],
    }
  }, [layerFilter, hosts, networks])

  const connectionsFiltered = useMemo(() => {
    if (layerFilter === 'Network Layer') {
      return networks.map((n) => ({
        id: `net-${n.id}`,
        source: n.name,
        type: 'segment',
        destination: 'Monitoring',
        status: n.status === 'ok' ? 'Online' : n.status === 'warning' ? 'Warning' : 'Critical',
        speed: '—',
      }))
    }
    if (layerFilter === 'Storage Layer' || layerFilter === 'Security Layer') return []
    return hosts.map((h) => ({
      id: `mon-${h.name}`,
      source: h.name,
      type: 'monitored',
      destination: 'Monitoring',
      status: h.status === 'ok' ? 'Online' : h.status === 'warning' ? 'Warning' : 'Critical',
      speed: '—',
    }))
  }, [layerFilter, hosts, networks])

  // Richer connection data from Observe microservice (servicesData): service counts per host
  const hostServiceStats = useMemo(() => {
    const stats = new Map<string, { total: number; critical: number; warning: number }>()
    if (!servicesData?.items) return stats
    const prefix = selectedWorkspaceId ? `ws${selectedWorkspaceId}-` : ''
    for (const item of servicesData.items as Array<{ host: string; status: string }>) {
      const hostName = item.host?.startsWith(prefix) ? item.host.slice(prefix.length) : item.host
      const cur = stats.get(hostName) ?? { total: 0, critical: 0, warning: 0 }
      cur.total += 1
      if (item.status === 'critical' || item.status === 'unreachable') cur.critical += 1
      else if (item.status === 'warning' || item.status === 'unknown') cur.warning += 1
      stats.set(hostName, cur)
    }
    return stats
  }, [servicesData?.items, selectedWorkspaceId])

  const connectionsWithServices = useMemo(() => {
    return connectionsFiltered.map((c) => {
      const st = hostServiceStats.get(c.source)
      return {
        ...c,
        serviceCount: st?.total ?? 0,
        criticalCount: st?.critical ?? 0,
        warningCount: st?.warning ?? 0,
      }
    })
  }, [connectionsFiltered, hostServiceStats])

  // Prefer API connections (Observe + Integrations) when available; enrich with service stats
  const connectionsForTab = useMemo(() => {
    if (apiConnectionsData != null) {
      const stats = apiConnectionsData.service_stats ?? {}
      return apiConnectionsData.connections.map((c) => ({
        id: (c as { id?: string }).id ?? `conn-${c.source}-${c.destination}`,
        source: c.source,
        type: c.type,
        destination: c.destination,
        status: c.status,
        serviceCount: stats[c.source]?.total ?? 0,
        criticalCount: stats[c.source]?.critical ?? 0,
        warningCount: stats[c.source]?.warning ?? 0,
        fromIntegration: (c as { source_origin?: string; integration?: string }).source_origin === 'integration',
        integrationName: (c as { integration?: string }).integration,
      }))
    }
    return connectionsWithServices.map((c) => ({ ...c, fromIntegration: false, integrationName: undefined }))
  }, [apiConnectionsData, connectionsWithServices])

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
        const alreadyListed = issues.some((i) => i.label.includes(s.service))
        if (!alreadyListed) {
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
        'Compute Layer': 'Hosts and servers monitored by QynSight',
        'Network Layer': 'Logical network segments (from host addressing)',
        'Storage Layer': 'Storage resources (define in Monitored Targets)',
        'Security Layer': 'Security zones and policies (define in Monitored Targets)',
      },
      zone: selectedWorkspaceId ? { id: selectedWorkspaceId, name: 'Workspace' } : null,
      zones: [...ZONE_OPTIONS, ...diagram.customZones],
      nodes: [
        ...layerFiltered.hostsFiltered.map((h) => ({
          id: h.name,
          name: h.name,
          type: 'host' as const,
          address: h.address,
          status: h.status,
          layer: 'Compute' as const,
          zone: diagram.hostZones[h.name] ?? 'Unassigned',
          position: diagram.nodePositions[h.name] ?? null,
        })),
        ...layerFiltered.networksFiltered.map((n) => ({
          id: n.id,
          name: n.name,
          type: 'network' as const,
          hostCount: n.hosts.length,
          status: n.status,
          layer: 'Network' as const,
        })),
      ],
      connections: connectionsWithServices.map((c) => ({
        source: c.source,
        destination: c.destination,
        type: c.type,
        status: c.status,
        ...(c.serviceCount !== undefined && c.type === 'monitored' ? { serviceCount: c.serviceCount, criticalCount: c.criticalCount ?? 0, warningCount: c.warningCount ?? 0 } : {}),
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
      mapContainerRef.current.requestFullscreen?.().catch(() => {})
    } else {
      document.exitFullscreen?.().catch(() => {})
    }
  }

  const handleExportPng = async () => {
    const el = exportAreaRef.current
    if (!el) return
    try {
      const canvas = await html2canvas(el, {
        backgroundColor: '#0f151d',
        scale: 2,
        logging: false,
        useCORS: true,
      })
      const dataUrl = canvas.toDataURL('image/png')
      const a = document.createElement('a')
      a.href = dataUrl
      a.download = `infrastructure-map-${new Date().toISOString().slice(0, 10)}.png`
      a.click()
    } catch (e) {
      console.error('Export PNG failed', e)
    }
  }

  const handleExportPdf = async () => {
    const el = exportAreaRef.current
    if (!el) return
    try {
      const canvas = await html2canvas(el, {
        backgroundColor: '#0f151d',
        scale: 2,
        logging: false,
        useCORS: true,
      })
      const imgData = canvas.toDataURL('image/png')
      const pdf = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' })
      const pageW = pdf.internal.pageSize.getWidth()
      const pageH = pdf.internal.pageSize.getHeight()
      const ratio = Math.min(pageW / canvas.width, pageH / canvas.height) * 0.95
      const w = canvas.width * ratio
      const h = canvas.height * ratio
      pdf.addImage(imgData, 'PNG', (pageW - w) / 2, (pageH - h) / 2, w, h)
      pdf.save(`infrastructure-map-${new Date().toISOString().slice(0, 10)}.pdf`)
    } catch (e) {
      console.error('Export PDF failed', e)
    }
  }

  // SVG export from real diagram state (no rasterization) — vector HLD/LLD
  const handleExportSvg = useCallback(() => {
    const MONITORING = { x: 20, y: 20, w: 100, h: 48 }
    const nodeW = 120
    const nodeH = 56
    const padding = 40
    const strokeOk = '#10b981'
    const strokeWarn = '#f59e0b'
    const strokeCrit = '#f43f5e'
    const strokeNet = '#0ea5e9'
    const bg = '#0f151d'
    const textColor = '#e2e8f0'

    const displayHosts = zoneFilter === 'All Zones' ? layerFiltered.hostsFiltered : hostsFilteredByZone
    const hostPositions = displayHosts.map((h, idx) => {
      const pos = diagram.nodePositions[h.name] ?? getNodePosition(h.name, idx)
      return { ...h, x: pos.x, y: pos.y }
    })
    const networkPositions = layerFiltered.networksFiltered.map((n, idx) => ({
      ...n,
      x: 80 + (idx % 4) * 160,
      y: 60 + Math.floor(idx / 4) * 100,
    }))

    const allX = [MONITORING.x, MONITORING.x + MONITORING.w, ...hostPositions.map((p) => p.x), ...hostPositions.map((p) => p.x + nodeW), ...networkPositions.map((p) => p.x), ...networkPositions.map((p) => p.x + nodeW)]
    const allY = [MONITORING.y, MONITORING.y + MONITORING.h, ...hostPositions.map((p) => p.y), ...hostPositions.map((p) => p.y + nodeH), ...networkPositions.map((p) => p.y), ...networkPositions.map((p) => p.y + nodeH)]
    const minX = Math.min(...allX) - padding
    const minY = Math.min(...allY) - padding
    const maxX = Math.max(...allX) + padding
    const maxY = Math.max(...allY) + padding
    const width = maxX - minX
    const height = maxY - minY

    const line = (x1: number, y1: number, x2: number, y2: number, stroke: string) =>
      `<line x1="${x1 - minX}" y1="${y1 - minY}" x2="${x2 - minX}" y2="${y2 - minY}" stroke="${stroke}" stroke-width="1.5" stroke-dasharray="4,2"/>`
    const rect = (x: number, y: number, w: number, h: number, stroke: string, fill = 'rgba(15,23,29,0.95)') =>
      `<rect x="${x - minX}" y="${y - minY}" width="${w}" height="${h}" rx="8" fill="${fill}" stroke="${stroke}" stroke-width="2"/>`
    const label = (x: number, y: number, w: number, _h: number, lines: string[]) => {
      const cx = x - minX + w / 2
      const ty = y - minY + 14
      return lines.map((t, i) => `<text x="${cx}" y="${ty + i * 12}" text-anchor="middle" font-size="10" fill="${textColor}" font-family="system-ui,sans-serif">${escapeXml(t)}</text>`).join('')
    }
    function escapeXml(s: string): string {
      return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
    }

    const lines: string[] = []
    hostPositions.forEach((h) => {
      const cx = h.x + nodeW / 2
      const cy = h.y + nodeH / 2
      const mx = MONITORING.x + MONITORING.w / 2
      const my = MONITORING.y + MONITORING.h / 2
      const stroke = h.status === 'ok' ? strokeOk : h.status === 'warning' ? strokeWarn : strokeCrit
      lines.push(line(cx, cy, mx, my, stroke))
    })
    networkPositions.forEach((n) => {
      const cx = n.x + nodeW / 2
      const cy = n.y + nodeH / 2
      const mx = MONITORING.x + MONITORING.w / 2
      const my = MONITORING.y + MONITORING.h / 2
      lines.push(line(cx, cy, mx, my, strokeNet))
    })

    const rectsAndLabels: string[] = []
    rectsAndLabels.push(rect(MONITORING.x, MONITORING.y, MONITORING.w, MONITORING.h, strokeNet))
    rectsAndLabels.push(label(MONITORING.x, MONITORING.y, MONITORING.w, MONITORING.h, ['Monitoring', 'QynSight']))
    hostPositions.forEach((h) => {
      const stroke = h.status === 'ok' ? strokeOk : h.status === 'warning' ? strokeWarn : strokeCrit
      rectsAndLabels.push(rect(h.x, h.y, nodeW, nodeH, stroke))
      rectsAndLabels.push(label(h.x, h.y, nodeW, nodeH, [h.name, statusLabel(h.status)]))
    })
    networkPositions.forEach((n) => {
      rectsAndLabels.push(rect(n.x, n.y, nodeW, nodeH, strokeNet))
      rectsAndLabels.push(label(n.x, n.y, nodeW, nodeH, [n.name, `${n.hosts.length} hosts`]))
    })

    const svg = `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height}" width="${width}" height="${height}" style="background:${bg}">
  ${lines.join('\n  ')}
  ${rectsAndLabels.join('\n  ')}
</svg>`
    const blob = new Blob([svg], { type: 'image/svg+xml' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `infrastructure-map-${designLevel}-${new Date().toISOString().slice(0, 10)}.svg`
    a.click()
    URL.revokeObjectURL(url)
  }, [diagram.nodePositions, layerFiltered.hostsFiltered, layerFiltered.networksFiltered, hostsFilteredByZone, zoneFilter, designLevel, getNodePosition])

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
    { id: 'ports' as const, label: 'Port Scan' },
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
              Export JSON
            </button>
            <button
              type="button"
              onClick={handleExportPng}
              className="inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white/80 hover:bg-white/10"
            >
              Export PNG
            </button>
            <button
              type="button"
              onClick={handleExportSvg}
              className="inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white/80 hover:bg-white/10"
              title="Vector diagram (HLD/LLD) from current topology"
            >
              Export SVG
            </button>
            <button
              type="button"
              onClick={handleExportPdf}
              className="inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white/80 hover:bg-white/10"
            >
              Export PDF
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
            <label className="inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white/80">
              <input
                type="checkbox"
                checked={autoRefresh}
                onChange={(e) => setAutoRefresh(e.target.checked)}
                className="rounded border-white/30 bg-white/10 text-sky-500 focus:ring-sky-500/50"
              />
              Auto-refresh
            </label>
            {autoRefresh && (
              <select
                value={autoRefreshSeconds}
                onChange={(e) => setAutoRefreshSeconds(Number(e.target.value))}
                className="rounded-lg border border-white/10 bg-white/5 px-2 py-1.5 text-xs text-white focus:border-sky-500/50 focus:outline-none"
                title="Refresh interval"
              >
                <option value={15} className="bg-slate-900">15s</option>
                <option value={30} className="bg-slate-900">30s</option>
                <option value={60} className="bg-slate-900">60s</option>
              </select>
            )}
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
        <select
          value={zoneFilter}
          onChange={(e) => setZoneFilter(e.target.value)}
          className="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white focus:border-sky-500/50 focus:outline-none focus:ring-1 focus:ring-sky-500/50"
          title="Filter by zone"
        >
          <option value="All Zones" className="bg-slate-900 text-white">All Zones</option>
          {ZONE_OPTIONS.filter((z) => z !== 'Unassigned').map((z) => (
            <option key={z} value={z} className="bg-slate-900 text-white">{z}</option>
          ))}
          {diagram.customZones.map((z) => (
            <option key={z} value={z} className="bg-slate-900 text-white">{z}</option>
          ))}
          <option value="Unassigned" className="bg-slate-900 text-white">Unassigned</option>
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

      {/* Tab content (ref for PNG/PDF export) */}
      <div ref={exportAreaRef} className="min-h-[400px] rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
        {activeTab === 'topology' && (
          <>
            <div className="mb-4 flex items-center justify-between">
              <div>
                <h3 className="text-sm font-semibold">Network Topology Map</h3>
                <p className="text-xs text-white/50">Layer: {layerFilter} — real data from Monitored Targets</p>
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
              ref={topologyRef}
              className="relative rounded-lg border border-white/10 bg-white/5 p-6 min-h-[420px] select-none"
              style={{
                transform: zoomLevel !== 'Fit to Screen' ? `scale(${zoomScale})` : undefined,
                transformOrigin: 'top left',
              }}
              onMouseMove={handleMouseMove}
              onMouseUp={handleMouseUp}
              onMouseLeave={handleMouseUp}
            >
              {layerFiltered.showStorage || layerFiltered.showSecurity ? (
                <div className="py-12 text-center text-sm text-white/50">
                  No {layerFilter === 'Storage Layer' ? 'storage' : 'security'} data. Define in Monitored Targets or integrate with external tools.
                </div>
              ) : !layerFiltered.hasData ? (
                <div className="py-12 text-center text-sm text-white/50">
                  No hosts. Add hosts in <Link to={selectedWorkspaceId ? `/app/workspaces/${selectedWorkspaceId}/observe/targets` : '#'} className="text-sky-300 hover:underline">Monitored Targets</Link>.
                </div>
              ) : viewType === 'By Zone' || viewType === 'Security Zones' ? (
                <ZoneBasedTopology
                  hostsByZone={hostsByZone}
                  zoneFilter={zoneFilter}
                  statusLabel={statusLabel}
                  allZonesList={allZonesList}
                  layerFiltered={layerFiltered}
                  portScansByHost={portScansByHost}
                />
              ) : designLevel === 'hld' ? (
                <div className="flex flex-col items-center gap-4">
                  <div className="rounded-xl border-2 border-sky-500/30 bg-sky-500/10 px-8 py-6 text-center">
                    <p className="text-xs font-medium text-sky-200/80 uppercase tracking-wider">Workspace Zone</p>
                    <p className="mt-1 text-2xl font-bold">
                      {layerFiltered.showCompute && layerFiltered.hostsFiltered.length > 0 && `${layerFiltered.hostsFiltered.length} hosts`}
                      {layerFiltered.showCompute && layerFiltered.showNetwork && layerFiltered.networksFiltered.length > 0 && ' · '}
                      {layerFiltered.showNetwork && layerFiltered.networksFiltered.length > 0 && `${layerFiltered.networksFiltered.length} networks`}
                    </p>
                    <p className="mt-1 text-xs text-white/60">{hostStatusCounts.ok} healthy, {hostStatusCounts.warning} warning, {hostStatusCounts.critical} critical</p>
                  </div>
                  <p className="text-xs text-white/40">HLD: High-level view. Export JSON/PNG/PDF for full HLD/LLD with zones.</p>
                </div>
              ) : (
                <LLDTopology
                  networks={networks}
                  diagram={diagram}
                  draggingNode={draggingNode}
                  handleNodeMouseDown={handleNodeMouseDown}
                  statusLabel={statusLabel}
                  portScansByHost={portScansByHost}
                />
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
            <p className="mb-2 text-xs text-white/50">Layer: {layerFilter}. Assign zones for HLD/LLD and use &quot;By Zone&quot; view.</p>
            <div className="mb-4 flex flex-wrap items-center gap-2">
              <input
                type="text"
                value={customZoneInput}
                onChange={(e) => setCustomZoneInput(e.target.value)}
                placeholder="Custom zone name"
                className="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white placeholder-white/40 focus:border-sky-500/50 focus:outline-none focus:ring-1 focus:ring-sky-500/50 w-40"
              />
              <button type="button" onClick={addCustomZone} className="rounded-lg border border-sky-500/30 bg-sky-500/20 px-3 py-1.5 text-xs text-sky-200 hover:bg-sky-500/30">
                Add zone
              </button>
            </div>
            {(layerFiltered.showStorage || layerFiltered.showSecurity) ? (
              <div className="py-12 text-center text-sm text-white/50">No {layerFilter === 'Storage Layer' ? 'storage' : 'security'} devices. Define in Monitored Targets.</div>
            ) : !layerFiltered.hasData ? (
              <div className="py-12 text-center text-sm text-white/50">No devices. Add hosts in Monitored Targets.</div>
            ) : (
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {layerFiltered.hostsFiltered.map((h) => (
                  <div key={h.name} className="rounded-xl border border-white/10 bg-white/5 p-4">
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0 flex-1">
                        <p className="font-semibold text-sm truncate" title={h.name}>{h.name}</p>
                        <p className="mt-1 text-xs text-white/60">server · {h.address}</p>
                        <p className="mt-1 text-xs text-white/50">Layer: Compute</p>
                        <div className="mt-2">
                          <label className="text-[10px] text-white/50 block mb-1">Zone</label>
                          <select
                            value={diagram.hostZones[h.name] ?? 'Unassigned'}
                            onChange={(e) => setHostZone(h.name, e.target.value as ZoneId)}
                            className="rounded border border-white/10 bg-white/5 px-2 py-1 text-xs text-white focus:border-sky-500/50 focus:outline-none w-full max-w-[140px]"
                          >
                            <option value="Unassigned" className="bg-slate-900">Unassigned</option>
                            {ZONE_OPTIONS.filter((z) => z !== 'Unassigned').map((z) => (
                              <option key={z} value={z} className="bg-slate-900">{z}</option>
                            ))}
                            {diagram.customZones.map((z) => (
                              <option key={z} value={z} className="bg-slate-900">{z}</option>
                            ))}
                          </select>
                        </div>
                      </div>
                      <span
                        className={`shrink-0 rounded-full px-2 py-1 text-[10px] font-medium ${
                          h.status === 'ok' ? 'bg-emerald-500/20 text-emerald-200' :
                          h.status === 'warning' ? 'bg-amber-500/20 text-amber-200' :
                          'bg-rose-500/20 text-rose-200'
                        }`}
                        title={statusTooltip(h.status)}
                      >
                        {statusLabel(h.status)}
                      </span>
                    </div>
                  </div>
                ))}
                {layerFiltered.networksFiltered.map((n) => (
                  <div key={n.id} className="rounded-xl border border-sky-500/20 bg-sky-500/5 p-4">
                    <div className="flex items-start justify-between">
                      <div className="min-w-0 flex-1">
                        <p className="font-semibold text-sm truncate" title={n.name}>{n.name}</p>
                        <p className="mt-1 text-xs text-white/60">network · {n.hosts.length} hosts</p>
                        <p className="mt-1 text-xs text-white/50">Layer: Network</p>
                      </div>
                      <span className={`shrink-0 rounded-full px-2 py-1 text-[10px] font-medium ${
                        n.status === 'ok' ? 'bg-sky-500/20 text-sky-200' :
                        n.status === 'warning' ? 'bg-amber-500/20 text-amber-200' :
                        'bg-rose-500/20 text-rose-200'
                      }`}>
                        {statusLabel(n.status)}
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
            <p className="mb-2 text-xs text-white/50">
              Layer: {layerFilter} — real data from QynSight microservice
              {apiConnectionsData?.from_integrations?.length ? (
                <span className="ml-2 rounded bg-sky-500/20 px-2 py-0.5 text-sky-200">+ {apiConnectionsData.from_integrations.join(', ')}</span>
              ) : null}
            </p>
            {connectionsForTab.length === 0 ? (
              <div className="py-12 text-center text-sm text-white/50">
                {layerFilter === 'Storage Layer' || layerFilter === 'Security Layer' ? `No ${layerFilter === 'Storage Layer' ? 'storage' : 'security'} connections.` : 'No connections. Add hosts in Monitored Targets or add external topology in Integrations.'}
              </div>
            ) : (
              <div className="space-y-3">
                {connectionsForTab.map((c) => (
                  <div key={c.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-white/10 bg-white/5 px-4 py-3">
                    <span className="font-mono text-xs">{c.source}</span>
                    <span className="text-white/40">— {c.type} —</span>
                    <span className="font-mono text-xs">{c.destination}</span>
                    <span
                      className={`text-xs font-medium ${
                        c.status === 'Online' ? 'text-emerald-400' :
                        c.status === 'Warning' ? 'text-amber-400' :
                        c.status === 'Pending' ? 'text-white/70' : 'text-rose-400'
                      }`}
                      title={statusTooltip(c.status)}
                    >
                      {c.status}
                    </span>
                    {c.serviceCount !== undefined && c.type === 'monitored' ? (
                      <span className="text-xs text-white/50">
                        {c.serviceCount} service{c.serviceCount !== 1 ? 's' : ''}
                        {(c.criticalCount ?? 0) > 0 && <span className="text-rose-400"> · {c.criticalCount} critical</span>}
                        {(c.warningCount ?? 0) > 0 && (c.criticalCount ?? 0) === 0 && <span className="text-amber-400"> · {c.warningCount} warning</span>}
                      </span>
                    ) : (
                      <span className="text-xs text-white/50">—</span>
                    )}
                    {c.fromIntegration && c.integrationName ? (
                      <span className="rounded bg-sky-500/20 px-2 py-0.5 text-[10px] text-sky-200" title="From external integration">Integration: {c.integrationName}</span>
                    ) : null}
                  </div>
                ))}
              </div>
            )}
          </>
        )}

        {activeTab === 'ports' && (
          <>
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
              <div>
                <h3 className="mb-1 text-sm font-semibold">Nmap Port Scan</h3>
                <p className="text-xs text-white/50">
                  Port scan results from nmap. Scans run when hosts are saved in Monitored Targets, or use Perform Scan for custom options. Requires nmap on the server.
                </p>
              </div>
              <button
                type="button"
                onClick={() => {
                  setScanOptions((prev) => ({
                    ...prev,
                    hostIds: (portScansData ?? []).map((ps) => ps.host_id),
                  }))
                  setScanModalOpen(true)
                  setScanError(null)
                }}
                disabled={(portScansData ?? []).length === 0}
                className="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-500 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <path d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
                </svg>
                Perform Scan
              </button>
            </div>

            {scanModalOpen && (
              <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" onClick={() => !scanning && setScanModalOpen(false)}>
                <div className="w-full max-w-md rounded-xl border border-white/10 bg-slate-900 p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
                  <h4 className="mb-4 text-base font-semibold">Port Scan Options</h4>
                  <div className="space-y-4">
                    <div>
                      <label className="mb-1 block text-xs font-medium text-white/80">Port range</label>
                      <div className="flex flex-wrap gap-2">
                        {(['top100', 'all', 'range'] as const).map((p) => (
                          <label key={p} className="flex cursor-pointer items-center gap-2">
                            <input
                              type="radio"
                              name="ports"
                              checked={scanOptions.ports === p}
                              onChange={() => setScanOptions((prev) => ({ ...prev, ports: p }))}
                              className="rounded border-white/20"
                            />
                            <span className="text-sm">
                              {p === 'top100' ? 'Top 100' : p === 'all' ? 'All ports (1-65535)' : 'Custom range'}
                            </span>
                          </label>
                        ))}
                      </div>
                      {scanOptions.ports === 'range' && (
                        <input
                          type="text"
                          value={scanOptions.portsRange}
                          onChange={(e) => setScanOptions((prev) => ({ ...prev, portsRange: e.target.value }))}
                          placeholder="e.g. 1-1024 or 80,443,8080"
                          className="mt-2 w-full rounded border border-white/10 bg-white/5 px-3 py-2 text-sm text-white placeholder-white/40 focus:border-sky-500/50 focus:outline-none"
                        />
                      )}
                    </div>
                    <div>
                      <label className="mb-1 block text-xs font-medium text-white/80">Protocol</label>
                      <div className="flex gap-4">
                        {(['tcp', 'udp'] as const).map((prot) => (
                          <label key={prot} className="flex cursor-pointer items-center gap-2">
                            <input
                              type="radio"
                              name="protocol"
                              checked={scanOptions.protocol === prot}
                              onChange={() => setScanOptions((prev) => ({ ...prev, protocol: prot }))}
                              className="rounded border-white/20"
                            />
                            <span className="text-sm uppercase">{prot}</span>
                          </label>
                        ))}
                      </div>
                      <p className="mt-1 text-[10px] text-white/50">UDP scan may require root on Linux.</p>
                    </div>
                    <div>
                      <label className="mb-1 block text-xs font-medium text-white/80">Hosts to scan</label>
                      <p className="text-xs text-white/60">
                        {scanOptions.hostIds.length === 0
                          ? 'All hosts in workspace'
                          : `${scanOptions.hostIds.length} host(s) selected`}
                      </p>
                      {scanOptions.hostIds.length > 0 && (
                        <button
                          type="button"
                          onClick={() => setScanOptions((prev) => ({ ...prev, hostIds: [] }))}
                          className="mt-1 text-[10px] text-sky-400 hover:underline"
                        >
                          Switch to all hosts
                        </button>
                      )}
                    </div>
                    {scanOptions.ports === 'all' && (
                      <p className="rounded bg-amber-500/10 border border-amber-500/20 px-3 py-2 text-xs text-amber-200">
                        All ports (1–65535) can take several minutes per host. Scan runs in background—you can close this and continue working; results will appear when ready.
                      </p>
                    )}
                  </div>
                  {scanError && (
                    <p className="mt-3 text-sm text-rose-400">{scanError}</p>
                  )}
                  <div className="mt-6 flex justify-end gap-2">
                    <button
                      type="button"
                      onClick={() => !scanning && setScanModalOpen(false)}
                      className="rounded-lg border border-white/20 px-4 py-2 text-sm text-white/80 hover:bg-white/5"
                    >
                      Cancel
                    </button>
                    <button
                      type="button"
                      onClick={async () => {
                        if (!selectedWorkspaceId || scanning) return
                        setScanning(true)
                        setScanError(null)
                        try {
                          const res = await observeService.runPortScans(Number(selectedWorkspaceId), {
                            hostIds: scanOptions.hostIds.length > 0 ? scanOptions.hostIds : undefined,
                            ports: scanOptions.ports,
                            portsRange: scanOptions.ports === 'range' ? scanOptions.portsRange : undefined,
                            protocol: scanOptions.protocol,
                          })
                          if (res.errors.length > 0) {
                            setScanError(res.errors.join('; '))
                          } else {
                            setScanModalOpen(false)
                            setScanStartedMessage(`Scan started for ${res.scanned} host(s). Results will appear when ready—refresh or wait for auto-refresh.`)
                            setTimeout(() => setScanStartedMessage(null), 8000)
                            refreshPortScans()
                          }
                        } catch (e) {
                          setScanError(e instanceof Error ? e.message : 'Scan failed')
                        } finally {
                          setScanning(false)
                        }
                      }}
                      disabled={scanning}
                      className="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-500 disabled:opacity-50"
                    >
                      {scanning ? 'Starting…' : 'Start Scan'}
                    </button>
                  </div>
                </div>
              </div>
            )}

            {scanStartedMessage && (
              <div className="mb-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
                {scanStartedMessage}
              </div>
            )}
            {(portScansData ?? []).length === 0 ? (
              <div className="py-12 text-center text-sm text-white/50">
                No port scan data. Add hosts in <Link to={selectedWorkspaceId ? `/app/workspaces/${selectedWorkspaceId}/observe/targets` : '#'} className="text-sky-300 hover:underline">Monitored Targets</Link> and save to trigger nmap scans.
              </div>
            ) : (
              <div className="space-y-4">
                {(portScansData ?? []).map((ps) => (
                  <div key={ps.host_id} className="rounded-xl border border-white/10 bg-white/5 p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2 mb-3">
                      <div>
                        <p className="font-semibold text-sm">{ps.host_name}</p>
                        <p className="text-xs text-white/60">{ps.address}</p>
                      </div>
                      <div className="flex items-center gap-2">
                        <button
                          type="button"
                          onClick={() => {
                            setScanOptions((prev) => ({ ...prev, hostIds: [ps.host_id] }))
                            setScanModalOpen(true)
                            setScanError(null)
                          }}
                          className="rounded border border-sky-500/20 bg-sky-500/10 px-2 py-1 text-[10px] font-medium text-sky-200 hover:bg-sky-500/20"
                        >
                          Scan
                        </button>
                        {ps.scan?.status === 'completed' && (
                          <span className="rounded bg-emerald-500/20 px-2 py-1 text-[10px] font-medium text-emerald-200">
                            {ps.scan.open_ports_count ?? 0} open
                          </span>
                        )}
                        {ps.scan?.status === 'running' && (
                          <span className="rounded bg-amber-500/20 px-2 py-1 text-[10px] font-medium text-amber-200 animate-pulse">Scanning…</span>
                        )}
                        {ps.scan?.status === 'failed' && (
                          <span className="rounded bg-rose-500/20 px-2 py-1 text-[10px] font-medium text-rose-200" title={ps.scan.error_message ?? ''}>Failed</span>
                        )}
                        {ps.scan?.status === 'pending' && (
                          <span className="rounded bg-white/10 px-2 py-1 text-[10px] font-medium text-white/70">Pending</span>
                        )}
                        {!ps.scan && (
                          <span className="rounded bg-sky-500/20 px-2 py-1 text-[10px] font-medium text-sky-200" title="Scan will run when host is saved in Monitored Targets">Queued</span>
                        )}
                        {ps.scan?.scanned_at && (
                          <span className="text-[10px] text-white/50">
                            {new Date(ps.scan.scanned_at).toLocaleString()}
                          </span>
                        )}
                      </div>
                    </div>
                    {ps.ports.length > 0 ? (
                      <div className="flex flex-wrap gap-2">
                        {ps.ports.filter((p) => p.state === 'open').map((p) => (
                          <span
                            key={`${p.port}-${p.protocol}`}
                            className="inline-flex items-center gap-1 rounded bg-sky-500/20 px-2 py-1 text-xs font-mono text-sky-200"
                            title={p.service ? `${p.service}${p.version ? ' ' + p.version : ''}` : undefined}
                          >
                            {p.port}/{p.protocol}
                            {p.service && <span className="text-white/70">({p.service})</span>}
                          </span>
                        ))}
                      </div>
                    ) : ps.scan?.status === 'completed' ? (
                      <p className="text-xs text-white/50">No open ports found in scan range.</p>
                    ) : ps.scan?.status === 'running' || !ps.scan ? (
                      <p className="text-xs text-white/50 italic">
                        {ps.scan?.status === 'running' ? 'Scanning…' : 'Scan queued. Results will appear when the scan completes.'}
                      </p>
                    ) : null}
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
                  <span className={`text-xs font-medium ${connectionsForTab.filter((c) => c.status === 'Online').length === connectionsForTab.length && connectionsForTab.length > 0 ? 'text-emerald-400' : 'text-amber-400'}`}>
                    {connectionsForTab.filter((c) => c.status === 'Online').length}/{connectionsForTab.length} Active
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
          <div><dt className="font-medium text-white/80">Compute Layer</dt><dd>Hosts and servers monitored by QynSight (real data from Monitored Targets).</dd></div>
          <div><dt className="font-medium text-white/80">Network Layer</dt><dd>Logical network segments; derived from host addressing. Add network metadata in Monitored Targets for richer LLD.</dd></div>
          <div><dt className="font-medium text-white/80">Storage Layer</dt><dd>Storage resources. Define in Monitored Targets or integrate with storage monitoring for live data.</dd></div>
          <div><dt className="font-medium text-white/80">Security Layer</dt><dd>Security zones and policies. Define in Monitored Targets or link to security tools for live data.</dd></div>
        </dl>
      </details>
    </div>
  )
}
