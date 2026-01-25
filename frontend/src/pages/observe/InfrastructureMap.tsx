import { useState } from 'react'
import { useNetworkTopology } from '../../hooks/useObserveData'
import { PageHeader } from '../../components/observe/PageHeader'
import { Tabs } from '../../components/observe/Tabs'
import { StatusBadge } from '../../components/observe/StatusBadge'

export default function InfrastructureMap() {
  const { topology, loading } = useNetworkTopology()
  const [activeTab, setActiveTab] = useState('topology')
  const [viewType, setViewType] = useState('Logical View')
  const [layer, setLayer] = useState('All Layers')

  if (loading) {
    return <div className="text-sm text-white/60">Loading...</div>
  }

  const tabs = [
    { id: 'topology', label: 'Network Topology' },
    { id: 'devices', label: 'Device List' },
    { id: 'connections', label: 'Connections' },
    { id: 'health', label: 'Health Overview' },
  ]

  const getStatusIcon = (status: string) => {
    if (status === 'healthy') return '✓'
    if (status === 'degraded') return '⚠'
    if (status === 'critical') return '✗'
    return ''
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Infrastructure Map"
        subtitle="Visual network topology and infrastructure overview"
        actions={
          <>
            <button className="rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white/70">
              Export Map
            </button>
            <button className="rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white/70">
              Full Screen
            </button>
            <button className="rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white/70">
              Configure
            </button>
          </>
        }
      />

      <div className="flex gap-2">
        <select
          value={viewType}
          onChange={(e) => setViewType(e.target.value)}
          className="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white"
        >
          <option value="Logical View" className="bg-slate-900 text-white">Logical View</option>
          <option value="Physical View" className="bg-slate-900 text-white">Physical View</option>
        </select>
        <select
          value={layer}
          onChange={(e) => setLayer(e.target.value)}
          className="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white"
        >
          <option value="All Layers" className="bg-slate-900 text-white">All Layers</option>
          <option value="Network Only" className="bg-slate-900 text-white">Network Only</option>
        </select>
        <button className="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white/70">
          Fit to Screen
        </button>
      </div>

      <Tabs tabs={tabs} activeTab={activeTab} onTabChange={setActiveTab} />

      {activeTab === 'topology' && (
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <div className="mb-4">
            <h3 className="text-sm font-semibold">Network Topology Map</h3>
            <p className="text-xs text-white/60">Interactive infrastructure visualization</p>
          </div>
          <div className="relative h-[600px] rounded-lg bg-white/5 p-8">
            <div className="absolute inset-0 flex items-center justify-center">
              <div className="text-center">
                <p className="mb-4 text-sm text-white/60">Network Topology Visualization</p>
                <div className="grid grid-cols-3 gap-4">
                  {topology.map((node) => (
                    <div
                      key={node.id}
                      className="rounded-lg border border-white/10 bg-white/5 p-4 text-left"
                    >
                      <div className="mb-2 flex items-center justify-between">
                        <h4 className="text-xs font-semibold">{node.name}</h4>
                        <StatusBadge
                          status={node.status}
                          label={getStatusIcon(node.status)}
                        />
                      </div>
                      <p className="mb-2 text-[10px] text-white/60">{node.location}</p>
                      <div className="space-y-1 text-[10px] text-white/40">
                        {node.details.servers && <div>Servers: {node.details.servers}</div>}
                        {node.details.nets && <div>Nets: {node.details.nets}</div>}
                        {node.details.devices && <div>Devices: {node.details.devices}</div>}
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </div>
            <div className="absolute bottom-4 left-4 rounded-lg border border-white/10 bg-[#0f151d] p-3 text-xs">
              <div className="mb-2 font-semibold">Legend</div>
              <div className="space-y-1 text-white/60">
                <div className="flex items-center gap-2">
                  <div className="h-1 w-8 bg-sky-500" />
                  <span>Active Connection</span>
                </div>
                <div className="flex items-center gap-2">
                  <div className="h-1 w-8 border-b-2 border-dashed border-rose-500" />
                  <span>Degraded Connection</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      )}

      {activeTab !== 'topology' && (
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-10 text-center text-white">
          <p className="text-sm text-white/60">{tabs.find((t) => t.id === activeTab)?.label} view coming soon</p>
        </div>
      )}
    </div>
  )
}
