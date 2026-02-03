import { useWorkspaceContext } from '../../workspaces/WorkspaceContext'
import { useObserveMapHosts } from '../../hooks/useObserveData'
import { PageHeader } from '../../components/observe/PageHeader'
import { StatusBadge } from '../../components/observe/StatusBadge'

function mapStatusToBadge(s: string): 'healthy' | 'degraded' | 'critical' | 'warning' | 'processing' {
  switch (s) {
    case 'ok':
      return 'healthy'
    case 'warning':
      return 'warning'
    case 'critical':
    case 'unreachable':
      return 'critical'
    case 'unknown':
      return 'degraded'
    case 'pending':
    default:
      return 'processing'
  }
}

export default function InfrastructureMap() {
  const { selectedWorkspaceId } = useWorkspaceContext()
  const { hosts, loading } = useObserveMapHosts(selectedWorkspaceId)

  if (loading) {
    return <div className="text-sm text-white/60">Loading...</div>
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Infrastructure Map"
        subtitle="Monitored target hosts and status from Observe"
        actions={
          <>
            <button title="Coming soon" disabled className="cursor-not-allowed rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white/40">
              Export Map
            </button>
            <button title="Coming soon" disabled className="cursor-not-allowed rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white/40">
              Full Screen
            </button>
            <button title="Coming soon" disabled className="cursor-not-allowed rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white/40">
              Configure
            </button>
          </>
        }
      />

      {hosts.length === 0 ? (
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-10 text-center text-white/60">
          No target hosts. Add hosts and services in <strong>Monitored Targets</strong>.
        </div>
      ) : (
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <h3 className="mb-4 text-sm font-semibold">Hosts</h3>
          <p className="mb-4 text-xs text-white/60">Status is derived from the worst service state per host.</p>
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {hosts.map((h) => (
              <div
                key={h.name}
                className="rounded-lg border border-white/10 bg-white/5 p-4 flex items-center justify-between"
              >
                <div>
                  <div className="font-medium text-sm">{h.name}</div>
                  <div className="text-xs text-white/60">{h.address}</div>
                </div>
                <StatusBadge status={mapStatusToBadge(h.status)} label={h.status} />
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
