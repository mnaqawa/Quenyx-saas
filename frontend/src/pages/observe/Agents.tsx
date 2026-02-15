/**
 * Agents — Install and manage PortShield agents for cross-network monitoring and asset inventory.
 */
import { useState, useEffect, useCallback } from 'react'
import { useParams } from 'react-router-dom'
import { useWorkspaceContext } from '../../workspaces/WorkspaceContext'
import { PageHeader } from '../../components/observe/PageHeader'
import { agentService } from '../../services/agentService'
import type {
  Agent,
  ProtocolInfo,
  PermissionInfo,
  EnrollmentTokenResponse,
} from '../../services/agentService'

function statusBadge(status: string) {
  const styles: Record<string, string> = {
    online: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40',
    offline: 'bg-amber-500/20 text-amber-300 border-amber-500/40',
    stale: 'bg-amber-500/20 text-amber-300 border-amber-500/40',
    error: 'bg-red-500/20 text-red-300 border-red-500/40',
  }
  const s = status?.toLowerCase() ?? 'unknown'
  return (
    <span
      className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${styles[s] ?? 'bg-white/10 text-white/70 border-white/20'}`}
    >
      {s}
    </span>
  )
}

function CopyButton({ text, label = 'Copy' }: { text: string; label?: string }) {
  const [copied, setCopied] = useState(false)
  const copy = () => {
    navigator.clipboard.writeText(text).then(() => {
      setCopied(true)
      setTimeout(() => setCopied(false), 2000)
    })
  }
  return (
    <button
      type="button"
      onClick={copy}
      className="rounded border border-white/20 bg-white/5 px-2 py-1 text-xs text-white/70 hover:bg-white/10 hover:text-white"
    >
      {copied ? 'Copied!' : label}
    </button>
  )
}

interface AgentsProps {
  /** When true, hide PageHeader (e.g. when embedded in Integrations page) */
  embedded?: boolean
}

export default function Agents({ embedded = false }: AgentsProps) {
  const { id } = useParams<{ id: string }>()
  const { selectedWorkspaceId, allowedByKey } = useWorkspaceContext()
  const workspaceId = id || selectedWorkspaceId

  const [agents, setAgents] = useState<Agent[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [installModalOpen, setInstallModalOpen] = useState(false)
  const [enrollmentResult, setEnrollmentResult] = useState<EnrollmentTokenResponse | null>(null)
  const [creating, setCreating] = useState(false)
  const [metadata, setMetadata] = useState<{
    protocols: Record<string, ProtocolInfo>
    permissions: Record<string, PermissionInfo>
  }>({ protocols: {}, permissions: {} })

  // When embedded in Integrations, agents are platform-wide (ShieldObserve, ShieldInventory, VA, etc.)
  const canEdit = embedded
    ? !!selectedWorkspaceId
    : !!(allowedByKey['shieldobserve'] ?? false)

  const fetchAgents = useCallback(async () => {
    if (!workspaceId) return
    try {
      setLoading(true)
      setError(null)
      const list = await agentService.list(workspaceId)
      setAgents(list)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load agents')
    } finally {
      setLoading(false)
    }
  }, [workspaceId])

  const fetchMetadata = useCallback(async () => {
    if (!workspaceId) return
    try {
      const m = await agentService.getMetadata(workspaceId)
      setMetadata(m)
    } catch {
      // ignore
    }
  }, [workspaceId])

  useEffect(() => {
    fetchAgents()
  }, [fetchAgents])

  useEffect(() => {
    if (installModalOpen) fetchMetadata()
  }, [installModalOpen, fetchMetadata])

  const handleInstallClick = () => {
    setEnrollmentResult(null)
    setInstallModalOpen(true)
  }

  const handleGenerateToken = async (opts: {
    primary_protocol?: string
    enabled_protocols?: string[]
    permissions?: string[]
    expires_hours?: number
  }) => {
    if (!workspaceId) return
    try {
      setCreating(true)
      const result = await agentService.createEnrollmentToken(workspaceId, opts)
      setEnrollmentResult(result)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create enrollment token')
    } finally {
      setCreating(false)
    }
  }

  const handleDeleteAgent = async (agentId: string) => {
    if (!workspaceId || !confirm('Remove this agent? It will stop reporting.')) return
    try {
      await agentService.delete(workspaceId, agentId)
      await fetchAgents()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to delete agent')
    }
  }

  const formatDate = (s: string | null) => {
    if (!s) return '—'
    try {
      const d = new Date(s)
      return d.toLocaleString()
    } catch {
      return s
    }
  }

  return (
    <div className="space-y-6">
      {!embedded && (
        <PageHeader
          title="Agents"
          subtitle="Install agents on servers and workstations for cross-network monitoring and asset inventory."
        />
      )}

      {error && (
        <div className="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-2 text-sm text-red-200">
          {error}
        </div>
      )}

      <div className="flex items-center justify-between">
        <p className="text-sm text-white/60">
          Agents push metrics and inventory to the platform. Works across firewalls—only outbound HTTPS required.
        </p>
        {canEdit && (
          <button
            type="button"
            onClick={handleInstallClick}
            className="rounded-lg border border-sky-500/50 bg-sky-500/20 px-4 py-2 text-sm font-medium text-sky-200 hover:bg-sky-500/30"
          >
            Install Agent
          </button>
        )}
      </div>

      {loading ? (
        <div className="rounded-xl border border-white/10 bg-white/5 p-8 text-center text-white/60">
          Loading agents…
        </div>
      ) : agents.length === 0 ? (
        <div className="rounded-xl border border-white/10 bg-white/5 p-12 text-center">
          <p className="text-white/60">No agents enrolled yet.</p>
          <p className="mt-2 text-sm text-white/40">
            Click &quot;Install Agent&quot; to generate an enrollment token and get install instructions for Linux,
            Windows, or macOS.
          </p>
          {canEdit && (
            <button
              type="button"
              onClick={handleInstallClick}
              className="mt-4 rounded-lg border border-sky-500/50 bg-sky-500/20 px-4 py-2 text-sm text-sky-200 hover:bg-sky-500/30"
            >
              Install Agent
            </button>
          )}
        </div>
      ) : (
        <div className="overflow-hidden rounded-xl border border-white/10 bg-white/5">
          <table className="min-w-full divide-y divide-white/10">
            <thead>
              <tr>
                <th className="px-4 py-3 text-left text-xs font-medium uppercase text-white/50">Hostname</th>
                <th className="px-4 py-3 text-left text-xs font-medium uppercase text-white/50">OS / Arch</th>
                <th className="px-4 py-3 text-left text-xs font-medium uppercase text-white/50">Protocol</th>
                <th className="px-4 py-3 text-left text-xs font-medium uppercase text-white/50">Status</th>
                <th className="px-4 py-3 text-left text-xs font-medium uppercase text-white/50">Last seen</th>
                <th className="px-4 py-3 text-right text-xs font-medium uppercase text-white/50">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-white/5">
              {agents.map((a) => (
                <tr key={a.id} className="hover:bg-white/5">
                  <td className="px-4 py-3 text-sm text-white">{a.hostname}</td>
                  <td className="px-4 py-3 text-sm text-white/70">
                    {[a.os, a.arch].filter(Boolean).join(' / ') || '—'}
                  </td>
                  <td className="px-4 py-3 text-sm text-white/70">{a.primary_protocol}</td>
                  <td className="px-4 py-3">{statusBadge(a.status)}</td>
                  <td className="px-4 py-3 text-sm text-white/60">{formatDate(a.last_seen_at)}</td>
                  <td className="px-4 py-3 text-right">
                    {canEdit && (
                      <button
                        type="button"
                        onClick={() => handleDeleteAgent(a.id)}
                        className="text-xs text-red-400 hover:text-red-300"
                      >
                        Remove
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {installModalOpen && (
        <InstallAgentModal
          metadata={metadata}
          enrollmentResult={enrollmentResult}
          creating={creating}
          onGenerateToken={handleGenerateToken}
          onClose={() => {
            setInstallModalOpen(false)
            setEnrollmentResult(null)
            fetchAgents()
          }}
        />
      )}
    </div>
  )
}

interface InstallAgentModalProps {
  metadata: { protocols: Record<string, ProtocolInfo>; permissions: Record<string, PermissionInfo> }
  enrollmentResult: EnrollmentTokenResponse | null
  creating: boolean
  onGenerateToken: (opts: {
    primary_protocol?: string
    enabled_protocols?: string[]
    permissions?: string[]
    expires_hours?: number
  }) => void
  onClose: () => void
}

function InstallAgentModal({
  metadata,
  enrollmentResult,
  creating,
  onGenerateToken,
  onClose,
}: InstallAgentModalProps) {
  const [primaryProtocol, setPrimaryProtocol] = useState('psap')
  const [permissions, setPermissions] = useState<string[]>(['system_metrics', 'inventory', 'filesystem'])
  const [expiresHours, setExpiresHours] = useState<number | 'never'>(24)

  const togglePermission = (key: string) => {
    setPermissions((prev) =>
      prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key]
    )
  }

  const handleGenerate = () => {
    onGenerateToken({
      primary_protocol: primaryProtocol,
      enabled_protocols: [primaryProtocol],
      permissions: permissions.length ? permissions : ['system_metrics', 'inventory', 'filesystem'],
      expires_hours: expiresHours === 'never' ? 0 : expiresHours,
    })
  }

  const protocols = metadata.protocols ?? {}
  const perms = metadata.permissions ?? {}

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
      <div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl border border-white/10 bg-[#0f151d] shadow-xl">
        <div className="sticky top-0 flex items-center justify-between border-b border-white/10 bg-[#0f151d] px-6 py-4">
          <h2 className="text-lg font-semibold text-white">Install Agent</h2>
          <button
            type="button"
            onClick={onClose}
            className="rounded p-1 text-white/60 hover:bg-white/10 hover:text-white"
          >
            ✕
          </button>
        </div>

        <div className="space-y-6 p-6">
          {!enrollmentResult ? (
            <>
              <section>
                <h3 className="mb-2 text-sm font-medium text-white/80">Protocol</h3>
                <p className="mb-3 text-xs text-white/50">
                  Choose how the agent communicates. HTTP API (push) works across firewalls.
                </p>
                <div className="space-y-2">
                  {Object.entries(protocols).map(([key, info]) => (
                    <label
                      key={key}
                      className="flex cursor-pointer items-start gap-3 rounded-lg border border-white/10 bg-white/5 p-3 hover:bg-white/10"
                    >
                      <input
                        type="radio"
                        name="primary_protocol"
                        checked={primaryProtocol === key}
                        onChange={() => setPrimaryProtocol(key)}
                        className="mt-1"
                      />
                      <div>
                        <span className="font-medium text-white">{info.label}</span>
                        <p className="text-xs text-white/60">{info.description}</p>
                        {info.port && (
                          <span className="mt-1 inline-block text-xs text-white/40">Port {info.port}</span>
                        )}
                      </div>
                    </label>
                  ))}
                </div>
              </section>

              <section>
                <h3 className="mb-2 text-sm font-medium text-white/80">Permissions</h3>
                <p className="mb-3 text-xs text-white/50">
                  What the agent is allowed to collect. Required permissions are pre-selected.
                </p>
                <div className="space-y-2">
                  {Object.entries(perms).map(([key, info]) => (
                    <label
                      key={key}
                      className="flex cursor-pointer items-center gap-3 rounded-lg border border-white/10 bg-white/5 p-3 hover:bg-white/10"
                    >
                      <input
                        type="checkbox"
                        checked={permissions.includes(key)}
                        onChange={() => togglePermission(key)}
                      />
                      <span className="text-white">{info.label}</span>
                      {info.required && (
                        <span className="text-xs text-amber-400">required</span>
                      )}
                    </label>
                  ))}
                </div>
              </section>

              <section>
                <h3 className="mb-2 text-sm font-medium text-white/80">Token expiry</h3>
                <select
                  value={expiresHours}
                  onChange={(e) => {
                    const v = e.target.value
                    setExpiresHours(v === 'never' ? 'never' : Number(v))
                  }}
                  className="rounded border border-white/20 bg-white/5 px-3 py-2 text-sm text-white"
                >
                  <option value={1}>1 hour</option>
                  <option value={24}>24 hours</option>
                  <option value={72}>72 hours</option>
                  <option value={168}>7 days</option>
                  <option value={720}>30 days</option>
                  <option value="never">Never expires</option>
                </select>
              </section>

              <div className="flex justify-end gap-2">
                <button
                  type="button"
                  onClick={onClose}
                  className="rounded-lg border border-white/20 px-4 py-2 text-sm text-white/70 hover:bg-white/10"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  onClick={handleGenerate}
                  disabled={creating}
                  className="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-500 disabled:opacity-50"
                >
                  {creating ? 'Generating…' : 'Generate token & instructions'}
                </button>
              </div>
            </>
          ) : (
            <EnrollmentResultView result={enrollmentResult} onClose={onClose} />
          )}
        </div>
      </div>
    </div>
  )
}

function EnrollmentResultView({
  result,
  onClose,
}: {
  result: EnrollmentTokenResponse
  onClose: () => void
}) {
  const instructions = result.install_instructions

  return (
    <div className="space-y-6">
      <div className="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-200">
        <strong>Save this token.</strong> It will not be shown again.{' '}
        {result.expires_at ? (
          <>Expires: {new Date(result.expires_at).toLocaleString()}</>
        ) : (
          <>Never expires</>
        )}
      </div>

      <div>
        <label className="mb-1 block text-xs text-white/50">Enrollment token</label>
        <div className="flex items-center gap-2">
          <code className="flex-1 truncate rounded border border-white/20 bg-white/5 px-3 py-2 text-sm text-white">
            {result.token}
          </code>
          <CopyButton text={result.token} label="Copy" />
        </div>
      </div>

      <div>
        <h3 className="mb-2 text-sm font-medium text-white/80">Install instructions</h3>
        {(['linux', 'windows', 'macos'] as const).map((platform) => {
          const inst = instructions[platform]
          if (!inst) return null
          const steps = inst.steps.join('\n')
          return (
            <div key={platform} className="mb-4">
              <h4 className="mb-2 text-sm font-medium text-white/70">{inst.title}</h4>
              <pre className="overflow-x-auto rounded border border-white/10 bg-black/30 p-4 text-xs text-white/90">
                {inst.steps.map((line, i) => (
                  <div key={i}>{line || ' '}</div>
                ))}
              </pre>
              <CopyButton text={steps} label={`Copy ${inst.title}`} />
            </div>
          )
        })}
      </div>

      <div className="flex justify-end">
        <button
          type="button"
          onClick={onClose}
          className="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-500"
        >
          Done
        </button>
      </div>
    </div>
  )
}
