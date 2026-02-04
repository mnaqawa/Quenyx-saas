import { Link } from 'react-router-dom'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'

export default function Help() {
  const { selectedWorkspaceId } = useWorkspaceContext()

  return (
    <div className="space-y-8 max-w-2xl">
      <div>
        <h1 className="text-2xl font-semibold text-white">Getting started</h1>
        <p className="mt-1 text-sm text-white/60">
          Set up monitoring and see real data in your workspace.
        </p>
      </div>

      <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-6 text-white">
        <h2 className="text-sm font-semibold uppercase tracking-wider text-white/70">Steps</h2>
        <ol className="mt-4 space-y-4">
          <li className="flex gap-4">
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-sky-500/30 text-sm font-bold text-sky-200">1</span>
            <div>
              <p className="font-medium text-white">Select a workspace</p>
              <p className="mt-1 text-xs text-white/60">
                Use the <strong>Workspace</strong> dropdown in the top bar to switch between Production Env and Staging Env. All data is scoped to the selected workspace.
              </p>
            </div>
          </li>
          <li className="flex gap-4">
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white/10 text-sm font-bold text-white/70">2</span>
            <div>
              <p className="font-medium text-white">Add hosts (Monitored Targets)</p>
              <p className="mt-1 text-xs text-white/60">
                In Observe → Monitored Targets, add the hosts you want to monitor (name and address). Without hosts, Real-time Monitoring and Dashboard will show an empty state and ask you to add hosts first.
              </p>
              {selectedWorkspaceId && (
                <Link
                  to={`/app/workspaces/${selectedWorkspaceId}/observe/targets`}
                  className="mt-2 inline-block text-xs text-sky-300 hover:underline"
                >
                  Open Monitored Targets →
                </Link>
              )}
            </div>
          </li>
          <li className="flex gap-4">
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white/10 text-sm font-bold text-white/70">3</span>
            <div>
              <p className="font-medium text-white">Add services</p>
              <p className="mt-1 text-xs text-white/60">
                For each host, configure services (e.g. CPU, disk, HTTP checks). Status and problems will appear on the Dashboard and in Real-time Monitoring.
              </p>
            </div>
          </li>
          <li className="flex gap-4">
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white/10 text-sm font-bold text-white/70">4</span>
            <div>
              <p className="font-medium text-white">View Real-time Monitoring &amp; Dashboard</p>
              <p className="mt-1 text-xs text-white/60">
                Dashboard shows a health-at-a-glance line and ShieldObserve summary. Real-time Monitoring shows monitoring server metrics and workspace host/service status.
              </p>
              {selectedWorkspaceId && (
                <div className="mt-2 flex gap-3">
                  <Link to="/dashboard" className="text-xs text-sky-300 hover:underline">Dashboard</Link>
                  <Link to={`/app/workspaces/${selectedWorkspaceId}/observe/real-time-monitoring`} className="text-xs text-sky-300 hover:underline">Real-time Monitoring</Link>
                </div>
              )}
            </div>
          </li>
        </ol>
      </section>

      <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-6 text-white">
        <h2 className="text-sm font-semibold uppercase tracking-wider text-white/70">More</h2>
        <ul className="mt-4 space-y-2 text-sm text-white/80">
          <li>
            <Link to="/integrations" className="text-sky-300 hover:underline">Integrations</Link>
            {' '}— Webhooks for alerts, API keys, and external topology for Infrastructure Map.
          </li>
          <li>
            {selectedWorkspaceId ? (
              <Link to={`/app/workspaces/${selectedWorkspaceId}/observe/infrastructure-map`} className="text-sky-300 hover:underline">Infrastructure Map</Link>
            ) : (
              <span>Infrastructure Map</span>
            )}
            {' '}— Visual topology, zones (DMZ, WebApp, DB, etc.), and export HLD/LLD (PNG, PDF, SVG, JSON).
          </li>
        </ul>
      </section>
    </div>
  )
}
