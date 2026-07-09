import { useCallback, useEffect, useState } from 'react'
import { useWorkspaceContext } from '../../workspaces/WorkspaceContext'
import { PageHeader } from '../../components/observe/PageHeader'
import { agentService, type Agent, type EnrollmentTokenResponse } from '../../services/agentService'
import {
  platformAgentService,
  type PlatformAgentDetail,
  type PlatformAgentMetadata,
  type CapabilityMatrixEntry,
  type FleetDashboard,
  type InstallerCatalog,
} from '../../services/platformAgentService'

type PlatformPermissionInfo = PlatformAgentMetadata['permissions'][string]

type Tab = 'fleet' | 'agents' | 'enroll' | 'installers' | 'capabilities' | 'troubleshoot'

function statusClass(s: string) {
  const map: Record<string, string> = {
    online: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40',
    offline: 'bg-amber-500/20 text-amber-300 border-amber-500/40',
    revoked: 'bg-red-500/20 text-red-300 border-red-500/40',
    pending: 'bg-sky-500/20 text-sky-300 border-sky-500/40',
  }
  return map[s] ?? 'bg-white/10 text-white/70 border-white/20'
}

function policyClass(s: string) {
  const map: Record<string, string> = {
    up_to_date: 'bg-emerald-500/20 text-emerald-300',
    policy_outdated: 'bg-amber-500/20 text-amber-300',
    upgrade_available: 'bg-sky-500/20 text-sky-300',
    unsupported_version: 'bg-red-500/20 text-red-300',
    policy_sync_required: 'bg-orange-500/20 text-orange-300',
  }
  return map[s] ?? 'bg-white/10 text-white/70'
}

function FleetStat({ label, value, accent }: { label: string; value: number; accent?: string }) {
  return (
    <div className="rounded-xl border border-white/10 bg-white/5 p-4">
      <p className="text-xs text-white/50">{label}</p>
      <p className={`mt-1 text-2xl font-semibold ${accent ?? 'text-white'}`}>{value}</p>
    </div>
  )
}

export default function PlatformAgents({ embedded = false }: { embedded?: boolean }) {
  const { selectedWorkspaceId, selectedWorkspaceRole } = useWorkspaceContext()
  const workspaceId = selectedWorkspaceId ? Number(selectedWorkspaceId) : null
  const canEdit = selectedWorkspaceRole === 'owner' || selectedWorkspaceRole === 'admin'

  const [tab, setTab] = useState<Tab>('fleet')
  const [agents, setAgents] = useState<Agent[]>([])
  const [platformAgents, setPlatformAgents] = useState<PlatformAgentDetail[]>([])
  const [metadata, setMetadata] = useState<PlatformAgentMetadata | null>(null)
  const [fleet, setFleet] = useState<FleetDashboard | null>(null)
  const [installers, setInstallers] = useState<InstallerCatalog | null>(null)
  const [selectedAgentId, setSelectedAgentId] = useState<string | null>(null)
  const [matrix, setMatrix] = useState<Record<string, CapabilityMatrixEntry>>({})
  const [enrollment, setEnrollment] = useState<EnrollmentTokenResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [wizardStep, setWizardStep] = useState(1)
  const [selectedPerms, setSelectedPerms] = useState<string[]>([])

  const load = useCallback(async () => {
    if (!workspaceId) return
    setLoading(true)
    setError(null)
    try {
      const [list, meta, plat, fleetData, installerData] = await Promise.all([
        agentService.list(workspaceId),
        platformAgentService.getMetadata(),
        platformAgentService.list(workspaceId),
        platformAgentService.getFleet(workspaceId),
        platformAgentService.getInstallers(workspaceId),
      ])
      setAgents(list)
      setMetadata(meta)
      setPlatformAgents(plat)
      setFleet(fleetData)
      setInstallers(installerData)
      setSelectedPerms(meta.default_permissions ?? [])
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Failed to load agents')
    } finally {
      setLoading(false)
    }
  }, [workspaceId])

  useEffect(() => {
    void load()
  }, [load])

  const loadMatrix = async (agentId: string) => {
    const detail = await platformAgentService.get(agentId)
    setSelectedAgentId(agentId)
    setMatrix(detail.capability_matrix ?? {})
  }

  const handleDeleteAgent = async (agentId: string, hostname: string) => {
    const msg =
      `Remove Platform Agent "${hostname}"?\n\n` +
      '• The agent will be revoked and stop reporting.\n' +
      '• Linked hosts will be marked "Agent removed" (not UNKNOWN).\n' +
      '• Historical metrics and alerts are preserved.\n' +
      '• Re-enroll a new agent to restore monitoring.'
    if (!window.confirm(msg)) return
    try {
      await agentService.delete(workspaceId!, agentId)
      await load()
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Delete failed')
    }
  }

  const runEnrollment = async () => {
    if (!workspaceId) return
    try {
      const res = await agentService.createEnrollmentToken(workspaceId, {
        permissions: selectedPerms,
        expires_hours: 24,
        name: 'Platform Agent enrollment',
      })
      setEnrollment(res)
      setWizardStep(4)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Enrollment failed')
    }
  }

  const tabs: { id: Tab; label: string }[] = [
    { id: 'fleet', label: 'Fleet dashboard' },
    { id: 'agents', label: 'Agents' },
    { id: 'installers', label: 'Installer center' },
    { id: 'enroll', label: 'Enrollment wizard' },
    { id: 'capabilities', label: 'Capability matrix' },
    { id: 'troubleshoot', label: 'Troubleshooting' },
  ]

  return (
    <div className="space-y-6">
      {!embedded ? (
        <PageHeader
          title="Quenyx Platform Agent"
          subtitle="One agent for all entitled modules — outbound HTTPS to QAG only."
        />
      ) : null}

      {error ? (
        <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-2 text-sm text-rose-100">{error}</div>
      ) : null}

      <div className="flex flex-wrap gap-2 border-b border-white/10 pb-2">
        {tabs.map((t) => (
          <button
            key={t.id}
            type="button"
            onClick={() => setTab(t.id)}
            className={[
              'rounded-lg px-3 py-1.5 text-sm font-medium transition',
              tab === t.id ? 'bg-white/15 text-white' : 'text-white/60 hover:text-white hover:bg-white/10',
            ].join(' ')}
          >
            {t.label}
          </button>
        ))}
        {metadata ? (
          <span className="ms-auto text-xs text-white/40 self-center">
            Gateway: {metadata.gateway_url}
          </span>
        ) : null}
      </div>

      {loading ? (
        <div className="rounded-xl border border-white/10 bg-white/5 p-8 text-center text-white/60">Loading…</div>
      ) : null}

      {!loading && tab === 'fleet' && fleet ? (
        <div className="space-y-6">
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-5">
            <FleetStat label="Total agents" value={fleet.fleet_summary.total} />
            <FleetStat label="Online" value={fleet.fleet_summary.online} accent="text-emerald-300" />
            <FleetStat label="Offline" value={fleet.fleet_summary.offline} accent="text-amber-300" />
            <FleetStat label="Outdated" value={fleet.fleet_summary.outdated} accent="text-orange-300" />
            <FleetStat label="Quarantined" value={fleet.fleet_summary.quarantined} accent="text-rose-300" />
            <FleetStat label="Updating" value={fleet.fleet_summary.updating} />
            <FleetStat label="Maintenance" value={fleet.fleet_summary.maintenance} />
            <FleetStat label="Enrollment pending" value={fleet.fleet_summary.enrollment_pending} />
            <FleetStat label="Disconnected" value={fleet.fleet_summary.disconnected} />
            <FleetStat label="Decommissioning" value={fleet.fleet_summary.decommissioning} />
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <div className="rounded-xl border border-white/10 bg-[#0f151d] p-4">
              <h3 className="text-sm font-semibold text-white mb-3">Version summary</h3>
              <ul className="space-y-1 text-sm text-white/70">
                {Object.entries(fleet.version_summary).map(([ver, count]) => (
                  <li key={ver} className="flex justify-between">
                    <span>{ver}</span>
                    <span className="text-white/40">{count}</span>
                  </li>
                ))}
                {Object.keys(fleet.version_summary).length === 0 ? <li className="text-white/40">No agents</li> : null}
              </ul>
            </div>
            <div className="rounded-xl border border-white/10 bg-[#0f151d] p-4">
              <h3 className="text-sm font-semibold text-white mb-3">Policy summary</h3>
              <ul className="space-y-1 text-sm">
                {Object.entries(fleet.policy_summary).map(([status, count]) => (
                  <li key={status} className="flex justify-between items-center">
                    <span className={`rounded px-2 py-0.5 text-xs ${policyClass(status)}`}>{status.replace(/_/g, ' ')}</span>
                    <span className="text-white/40">{count}</span>
                  </li>
                ))}
              </ul>
            </div>
          </div>

          <div className="rounded-xl border border-white/10 bg-[#0f151d] p-4">
            <h3 className="text-sm font-semibold text-white mb-3">Gateway summary</h3>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-xs text-white/50 border-b border-white/10">
                    <th className="py-2 text-start">Name</th>
                    <th className="py-2 text-start">Region</th>
                    <th className="py-2 text-start">Health</th>
                    <th className="py-2 text-start">Agents</th>
                    <th className="py-2 text-start">Endpoint</th>
                  </tr>
                </thead>
                <tbody>
                  {fleet.gateway_summary.map((gw) => (
                    <tr key={gw.uuid} className="border-b border-white/5">
                      <td className="py-2">{gw.name}</td>
                      <td className="py-2 text-white/60">{gw.region ?? '—'}</td>
                      <td className="py-2 capitalize">{gw.health_status}</td>
                      <td className="py-2">{gw.connected_agents}</td>
                      <td className="py-2 font-mono text-xs text-white/50">{gw.endpoint_url}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>

          <div className="grid gap-4 lg:grid-cols-3">
            <div className="rounded-xl border border-white/10 bg-[#0f151d] p-4">
              <h3 className="text-sm font-semibold text-white mb-2">Heartbeat statistics</h3>
              <p className="text-sm text-white/70">Total: {fleet.heartbeat_statistics.total_heartbeats}</p>
              <p className="text-sm text-white/70">Reporting: {fleet.heartbeat_statistics.agents_reporting}</p>
              <p className="text-sm text-white/70">Avg/agent: {fleet.heartbeat_statistics.avg_per_agent}</p>
            </div>
            <div className="rounded-xl border border-white/10 bg-[#0f151d] p-4">
              <h3 className="text-sm font-semibold text-white mb-2">Bandwidth</h3>
              <p className="text-sm text-white/70">Sent: {(fleet.bandwidth_statistics.bytes_sent / 1024).toFixed(1)} KB</p>
              <p className="text-sm text-white/70">Received: {(fleet.bandwidth_statistics.bytes_received / 1024).toFixed(1)} KB</p>
            </div>
            <div className="rounded-xl border border-white/10 bg-[#0f151d] p-4">
              <h3 className="text-sm font-semibold text-white mb-2">Recent enrollments</h3>
              <ul className="text-xs text-white/60 space-y-1">
                {fleet.recent_enrollments.slice(0, 5).map((e) => (
                  <li key={e.agent_uuid}>{e.hostname}</li>
                ))}
                {fleet.recent_enrollments.length === 0 ? <li>None in last 7 days</li> : null}
              </ul>
            </div>
          </div>

          {fleet.top_errors.length > 0 ? (
            <div className="rounded-xl border border-rose-500/20 bg-rose-500/5 p-4">
              <h3 className="text-sm font-semibold text-rose-200 mb-2">Top errors</h3>
              <ul className="text-xs text-rose-100/80 space-y-1">
                {fleet.top_errors.map((err) => (
                  <li key={err.agent_uuid}>
                    <strong>{err.hostname}</strong>: {err.error}
                  </li>
                ))}
              </ul>
            </div>
          ) : null}
        </div>
      ) : null}

      {!loading && tab === 'agents' ? (
        <div className="space-y-4">
          {canEdit ? (
            <button
              type="button"
              onClick={() => {
                setTab('enroll')
                setWizardStep(1)
                setEnrollment(null)
              }}
              className="rounded-lg border border-sky-500/50 bg-sky-500/20 px-4 py-2 text-sm text-sky-200"
            >
              Install Platform Agent
            </button>
          ) : null}
          {agents.length === 0 ? (
            <div className="rounded-xl border border-white/10 bg-white/5 p-10 text-center text-white/60">
              <p>No Platform Agents enrolled.</p>
              <p className="mt-2 text-sm text-white/40">Only outbound HTTPS to the Agent Gateway is required.</p>
            </div>
          ) : (
            <div className="overflow-x-auto rounded-xl border border-white/10">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-white/10 text-left text-xs text-white/50">
                    <th className="px-4 py-2">Hostname</th>
                    <th className="px-4 py-2">Status</th>
                    <th className="px-4 py-2">Policy</th>
                    <th className="px-4 py-2">Resources</th>
                    <th className="px-4 py-2">Last heartbeat</th>
                    <th className="px-4 py-2 text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {agents.map((a) => {
                    const plat = platformAgents.find((p) => p.uuid === a.id)
                    return (
                      <tr key={a.id} className="border-b border-white/5 hover:bg-white/5">
                        <td className="px-4 py-2 font-medium">{a.hostname}</td>
                        <td className="px-4 py-2">
                          <span className={`rounded-full border px-2 py-0.5 text-xs ${statusClass(plat?.lifecycle_status ?? a.status)}`}>
                            {plat?.lifecycle_status ?? a.status}
                          </span>
                        </td>
                        <td className="px-4 py-2">
                          {plat?.policy_status ? (
                            <span className={`rounded px-2 py-0.5 text-xs ${policyClass(plat.policy_status)}`}>
                              {plat.policy_status.replace(/_/g, ' ')}
                            </span>
                          ) : (
                            '—'
                          )}
                        </td>
                        <td className="px-4 py-2 text-white/60">
                          {plat?.managed_resource_count ?? 0} res · {plat?.plugin_count ?? 0} plugins
                        </td>
                        <td className="px-4 py-2 text-white/50">{a.last_seen_at ? new Date(a.last_seen_at).toLocaleString() : '—'}</td>
                        <td className="px-4 py-2 text-end">
                          <button
                            type="button"
                            className="me-2 text-xs text-sky-400"
                            onClick={() => {
                              setTab('capabilities')
                              void loadMatrix(a.id)
                            }}
                          >
                            Capabilities
                          </button>
                          {canEdit ? (
                            <button
                              type="button"
                              className="text-xs text-rose-400"
                              onClick={() => void handleDeleteAgent(a.id, a.hostname)}
                            >
                              Remove
                            </button>
                          ) : null}
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>
      ) : null}

      {!loading && tab === 'installers' && installers ? (
        <div className="space-y-6">
          <p className="text-sm text-white/70">
            Enterprise installer catalog — embed gateway URL, workspace ID, and enrollment token in silent deployments.
          </p>
          {Object.entries(installers.installers).map(([platform, formats]) => (
            <div key={platform} className="rounded-xl border border-white/10 bg-[#0f151d] p-4">
              <h3 className="text-sm font-semibold text-white capitalize mb-3">{platform}</h3>
              <ul className="space-y-2 text-sm">
                {(formats as Array<Record<string, string>>).map((item, i) => (
                  <li key={i} className="rounded bg-black/30 p-3 font-mono text-xs text-white/80">
                    <span className="text-sky-300">{item.format}</span>
                    {item.silent ? <pre className="mt-1 whitespace-pre-wrap text-white/60">{item.silent}</pre> : null}
                    {item.run ? <pre className="mt-1 whitespace-pre-wrap text-white/60">{item.run}</pre> : null}
                  </li>
                ))}
              </ul>
            </div>
          ))}
          {installers.enroll_command ? (
            <div className="rounded-xl border border-sky-500/30 bg-sky-500/10 p-4">
              <h3 className="text-sm font-semibold text-sky-200 mb-2">Manual enroll (after install)</h3>
              <pre className="overflow-x-auto text-xs text-white/80">{installers.enroll_command}</pre>
            </div>
          ) : null}
        </div>
      ) : null}

      {!loading && tab === 'enroll' ? (
        <div className="rounded-xl border border-white/10 bg-[#0f151d] p-6 space-y-4">
          <ol className="flex flex-wrap gap-4 text-xs text-white/50">
            {['Workspace', 'Permissions', 'Review', 'Install'].map((label, i) => (
              <li key={label} className={wizardStep >= i + 1 ? 'text-sky-300' : ''}>
                {i + 1}. {label}
              </li>
            ))}
          </ol>
          {wizardStep === 1 ? (
            <div>
              <p className="text-sm text-white/70">Workspace ID: <strong>{workspaceId}</strong></p>
              <button type="button" className="mt-4 rounded bg-white/10 px-3 py-1.5 text-sm" onClick={() => setWizardStep(2)}>
                Next: Choose permissions
              </button>
            </div>
          ) : null}
          {wizardStep === 2 && metadata ? (
            <div className="space-y-2">
              {(Object.entries(metadata.permissions) as [string, PlatformPermissionInfo][]).map(([key, p]) => (
                <label key={key} className="flex items-center gap-2 text-sm text-white/80">
                  <input
                    type="checkbox"
                    checked={selectedPerms.includes(key)}
                    disabled={p.required}
                    onChange={(e) => {
                      setSelectedPerms((prev) =>
                        e.target.checked ? [...prev, key] : prev.filter((x) => x !== key)
                      )
                    }}
                  />
                  {p.label}
                  {p.dangerous ? <span className="text-amber-400 text-xs">(approval required)</span> : null}
                </label>
              ))}
              <button type="button" className="mt-4 rounded bg-white/10 px-3 py-1.5 text-sm" onClick={() => setWizardStep(3)}>
                Next: Review
              </button>
            </div>
          ) : null}
          {wizardStep === 3 ? (
            <div>
              <p className="text-sm text-white/70">Default safe permissions — no SSH, automation, or compliance unless explicitly enabled.</p>
              <button type="button" className="mt-4 rounded bg-sky-500/30 px-3 py-1.5 text-sm text-sky-100" onClick={() => void runEnrollment()}>
                Generate enrollment token
              </button>
            </div>
          ) : null}
          {wizardStep === 4 && enrollment ? (
            <div className="space-y-3 text-sm">
              <p className="text-emerald-300">Token generated. Install the agent on your host:</p>
              <pre className="overflow-x-auto rounded bg-black/40 p-3 text-xs text-white/80">
                {enrollment.install_instructions?.linux?.steps?.join('\n')}
              </pre>
              <p className="text-white/50">Gateway URL: {enrollment.gateway_url ?? metadata?.gateway_url}</p>
            </div>
          ) : null}
        </div>
      ) : null}

      {!loading && tab === 'capabilities' ? (
        <div className="space-y-4">
          <select
            className="rounded border border-white/15 bg-black/30 px-3 py-2 text-sm text-white"
            value={selectedAgentId ?? ''}
            onChange={(e) => e.target.value && void loadMatrix(e.target.value)}
          >
            <option value="">Select agent…</option>
            {agents.map((a) => (
              <option key={a.id} value={a.id}>
                {a.hostname}
              </option>
            ))}
          </select>
          {selectedAgentId && Object.keys(matrix).length > 0 ? (
            <table className="w-full text-sm rounded-xl border border-white/10">
              <thead>
                <tr className="border-b border-white/10 text-xs text-white/50">
                  <th className="px-3 py-2 text-start">Capability</th>
                  <th className="px-3 py-2 text-start">Status</th>
                  <th className="px-3 py-2 text-start">Reason</th>
                </tr>
              </thead>
              <tbody>
                {Object.entries(matrix).map(([cap, entry]) => (
                  <tr key={cap} className="border-b border-white/5">
                    <td className="px-3 py-2 font-mono text-xs">{cap}</td>
                    <td className="px-3 py-2 capitalize">{entry.status.replace(/_/g, ' ')}</td>
                    <td className="px-3 py-2 text-white/50">{entry.reason ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <p className="text-white/50 text-sm">Select an agent to view its capability matrix.</p>
          )}
        </div>
      ) : null}

      {!loading && tab === 'troubleshoot' ? (
        <div className="rounded-xl border border-white/10 bg-[#0f151d] p-6 space-y-4 text-sm text-white/80">
          <h3 className="font-semibold text-white">Common issues</h3>
          <ul className="list-disc ps-5 space-y-2">
            <li>
              <strong>Host shows UNKNOWN after agent removal</strong> — Linked hosts should show &quot;Agent removed&quot;. Use Host
              lifecycle → Re-enable after re-enrolling.
            </li>
            <li>
              <strong>SSH / .ssh errors</strong> — Platform Agent uses push telemetry only. Disable pull/SSH checks on agent-enrolled
              hosts.
            </li>
            <li>
              <strong>Connection refused on :9444</strong> — Verify QAG is running and outbound HTTPS is allowed to{' '}
              {metadata?.gateway_url ?? 'https://cloud.quenyx.com:9444'}.
            </li>
            <li>
              <strong>403 on heartbeat</strong> — Agent may be revoked. Generate a new enrollment token.
            </li>
          </ul>
          <p className="text-white/50 text-xs">
            Agents never communicate with Laravel directly — only via Quenyx Agent Gateway (TLS 1.2+, outbound only).
          </p>
        </div>
      ) : null}
    </div>
  )
}
