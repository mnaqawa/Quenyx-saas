import { useCallback, useEffect, useState } from 'react'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'
import { PageHeader } from '../components/observe/PageHeader'
import { agentService, type Agent, type EnrollmentTokenResponse } from '../services/agentService'
import {
  platformAgentService,
  type PlatformAgentDetail,
  type PlatformAgentMetadata,
  type CapabilityMatrixEntry,
} from '../services/platformAgentService'

type Tab = 'agents' | 'enroll' | 'capabilities' | 'troubleshoot'

function statusClass(s: string) {
  const map: Record<string, string> = {
    online: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40',
    offline: 'bg-amber-500/20 text-amber-300 border-amber-500/40',
    revoked: 'bg-red-500/20 text-red-300 border-red-500/40',
    pending: 'bg-sky-500/20 text-sky-300 border-sky-500/40',
  }
  return map[s] ?? 'bg-white/10 text-white/70 border-white/20'
}

export default function PlatformAgents({ embedded = false }: { embedded?: boolean }) {
  const { selectedWorkspaceId, selectedWorkspaceRole } = useWorkspaceContext()
  const workspaceId = selectedWorkspaceId ? Number(selectedWorkspaceId) : null
  const canEdit = selectedWorkspaceRole === 'owner' || selectedWorkspaceRole === 'admin'

  const [tab, setTab] = useState<Tab>('agents')
  const [agents, setAgents] = useState<Agent[]>([])
  const [platformAgents, setPlatformAgents] = useState<PlatformAgentDetail[]>([])
  const [metadata, setMetadata] = useState<PlatformAgentMetadata | null>(null)
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
      const [list, meta, plat] = await Promise.all([
        agentService.list(workspaceId),
        platformAgentService.getMetadata(),
        platformAgentService.list(workspaceId),
      ])
      setAgents(list)
      setMetadata(meta)
      setPlatformAgents(plat)
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
    { id: 'agents', label: 'Agents' },
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
                    <th className="px-4 py-2">Modules</th>
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
                          <span className={`rounded-full border px-2 py-0.5 text-xs ${statusClass(a.status)}`}>
                            {a.status}
                          </span>
                        </td>
                        <td className="px-4 py-2 text-white/60">
                          {(plat?.enabled_modules ?? []).join(', ') || '—'}
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
              {Object.entries(metadata.permissions).map(([key, p]) => (
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
