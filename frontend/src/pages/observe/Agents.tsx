/**
 * Quenyx Platform Agent (QPA) — install and manage agents for all entitled modules.
 * Agents communicate outbound-only via Quenyx Agent Gateway (QAG) on HTTPS :9444.
 */
import { useState, useEffect, useCallback } from 'react'
import { useParams } from 'react-router-dom'
import { useWorkspaceContext } from '../../workspaces/WorkspaceContext'
import { useLanguage } from '../../i18n/LanguageContext'
import { PageHeader } from '../../components/observe/PageHeader'
import { agentService } from '../../services/agentService'
import type {
  Agent,
  ProtocolInfo,
  PermissionInfo,
  EnrollmentTokenResponse,
} from '../../services/agentService'

function statusBadge(status: string, t: (key: string) => string) {
  const styles: Record<string, string> = {
    online: 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40',
    offline: 'bg-amber-500/20 text-amber-300 border-amber-500/40',
    pending: 'bg-sky-500/20 text-sky-300 border-sky-500/40',
    revoked: 'bg-red-500/20 text-red-300 border-red-500/40',
    stale: 'bg-amber-500/20 text-amber-300 border-amber-500/40',
    error: 'bg-red-500/20 text-red-300 border-red-500/40',
  }
  const s = status?.toLowerCase() ?? 'unknown'
  const labelKey = `agents.status.${s}` as const
  const label = ['pending', 'online', 'offline', 'revoked'].includes(s) ? t(labelKey) : s
  return (
    <span
      className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${styles[s] ?? 'bg-white/10 text-white/70 border-white/20'}`}
    >
      {label}
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
  const { t } = useLanguage()
  const { id } = useParams<{ id: string }>()
  const { selectedWorkspaceId, allowedByKey, selectedWorkspaceRole, workspaces } = useWorkspaceContext()
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

  const canEdit =
    (selectedWorkspaceRole === 'owner' || selectedWorkspaceRole === 'admin') &&
    (embedded ? !!selectedWorkspaceId : !!(allowedByKey['qynsight'] ?? false))

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

  const handleGenerateToken = async (
    opts: {
      primary_protocol?: string
      enabled_protocols?: string[]
      permissions?: string[]
      expires_hours?: number
      target_os?: 'linux' | 'windows' | 'macos'
    },
    wsOverride?: string | number
  ) => {
    const ws = wsOverride ?? workspaceId
    if (!ws) return
    try {
      setCreating(true)
      const result = await agentService.createEnrollmentToken(ws, opts)
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
          title={t('agents.title')}
          subtitle={t('agents.subtitle')}
        />
      )}

      {error && (
        <div className="rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-2 text-sm text-red-200">
          {error}
        </div>
      )}

      <div className="flex items-center justify-between">
        <p className="text-sm text-white/60">{t('agents.description')}</p>
        {canEdit && (
          <button
            type="button"
            onClick={handleInstallClick}
            className="rounded-lg border border-sky-500/50 bg-sky-500/20 px-4 py-2 text-sm font-medium text-sky-200 hover:bg-sky-500/30"
          >
            {t('agents.install')}
          </button>
        )}
      </div>

      {loading ? (
        <div className="rounded-xl border border-white/10 bg-white/5 p-8 text-center text-white/60">
          {t('agents.loading')}
        </div>
      ) : agents.length === 0 ? (
        <div className="rounded-xl border border-white/10 bg-white/5 p-12 text-center">
          <p className="text-white/60">{t('agents.empty')}</p>
          <p className="mt-2 text-sm text-white/40">{t('agents.emptyHint')}</p>
          {canEdit && (
            <button
              type="button"
              onClick={handleInstallClick}
              className="mt-4 rounded-lg border border-sky-500/50 bg-sky-500/20 px-4 py-2 text-sm text-sky-200 hover:bg-sky-500/30"
            >
              {t('agents.install')}
            </button>
          )}
        </div>
      ) : (
        <div className="overflow-hidden rounded-xl border border-white/10 bg-white/5">
          <table className="min-w-full divide-y divide-white/10">
            <thead>
              <tr>
                <th className="px-4 py-3 text-left text-xs font-medium uppercase text-white/50">{t('agents.col.name')}</th>
                <th className="px-4 py-3 text-left text-xs font-medium uppercase text-white/50">{t('agents.col.hostname')}</th>
                <th className="px-4 py-3 text-left text-xs font-medium uppercase text-white/50">{t('agents.col.workspace')}</th>
                <th className="px-4 py-3 text-left text-xs font-medium uppercase text-white/50">{t('agents.col.os')}</th>
                <th className="px-4 py-3 text-left text-xs font-medium uppercase text-white/50">{t('agents.col.version')}</th>
                <th className="px-4 py-3 text-left text-xs font-medium uppercase text-white/50">{t('agents.col.status')}</th>
                <th className="px-4 py-3 text-left text-xs font-medium uppercase text-white/50">{t('agents.col.lastSeen')}</th>
                <th className="px-4 py-3 text-right text-xs font-medium uppercase text-white/50">{t('agents.col.actions')}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-white/5">
              {agents.map((a) => (
                <tr key={a.id} className="hover:bg-white/5">
                  <td className="px-4 py-3 text-sm text-white">{a.name ?? a.hostname}</td>
                  <td className="px-4 py-3 text-sm text-white/80">{a.hostname}</td>
                  <td className="px-4 py-3 text-sm text-white/70">{a.workspace_name ?? '—'}</td>
                  <td className="px-4 py-3 text-sm text-white/70">
                    {[a.os, a.arch].filter(Boolean).join(' / ') || '—'}
                  </td>
                  <td className="px-4 py-3 text-sm text-white/70">{a.agent_version ?? '—'}</td>
                  <td className="px-4 py-3">{statusBadge(a.status, t)}</td>
                  <td className="px-4 py-3 text-sm text-white/60">{formatDate(a.last_seen_at)}</td>
                  <td className="px-4 py-3 text-right">
                    {canEdit && (
                      <button
                        type="button"
                        onClick={() => handleDeleteAgent(a.id)}
                        className="text-xs text-red-400 hover:text-red-300"
                      >
                        {t('agents.remove')}
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {installModalOpen && workspaceId && (
        <InstallAgentModal
          workspaceId={workspaceId}
          workspaces={workspaces}
          metadata={metadata}
          enrollmentResult={enrollmentResult}
          creating={creating}
          onGenerateToken={handleGenerateToken}
          onVerify={fetchAgents}
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
  workspaceId: string | number
  workspaces: Array<{ id: number; name: string }>
  metadata: { protocols: Record<string, ProtocolInfo>; permissions: Record<string, PermissionInfo> }
  enrollmentResult: EnrollmentTokenResponse | null
  creating: boolean
  onGenerateToken: (opts: {
    primary_protocol?: string
    enabled_protocols?: string[]
    permissions?: string[]
    expires_hours?: number
    target_os?: 'linux' | 'windows' | 'macos'
  }, wsId?: string | number) => void
  onVerify: () => Promise<void>
  onClose: () => void
}

function InstallAgentModal({
  workspaceId,
  workspaces,
  metadata,
  enrollmentResult,
  creating,
  onGenerateToken,
  onVerify,
  onClose,
}: InstallAgentModalProps) {
  const { t } = useLanguage()
  const [step, setStep] = useState(1)
  const [selectedWs, setSelectedWs] = useState(String(workspaceId))
  const [primaryProtocol, setPrimaryProtocol] = useState('psap')
  const [targetOs, setTargetOs] = useState<'linux' | 'windows' | 'macos'>('linux')
  const [permissions, setPermissions] = useState<string[]>(['system_metrics', 'inventory', 'filesystem'])
  const [expiresHours, setExpiresHours] = useState<number | 'never'>(24)
  const [verifyStatus, setVerifyStatus] = useState<'idle' | 'waiting' | 'success' | 'timeout'>('idle')

  useEffect(() => {
    if (!enrollmentResult) return
    setStep(4)
    setVerifyStatus('waiting')
    const started = Date.now()
    const interval = window.setInterval(async () => {
      await onVerify()
      if (Date.now() - started > 120000) {
        setVerifyStatus('timeout')
        window.clearInterval(interval)
      }
    }, 5000)
    return () => window.clearInterval(interval)
  }, [enrollmentResult, onVerify])

  useEffect(() => {
    if (verifyStatus !== 'waiting') return
    const check = async () => {
      await onVerify()
    }
    check()
  }, [verifyStatus, onVerify])

  const togglePermission = (key: string) => {
    setPermissions((prev) =>
      prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key]
    )
  }

  const handleGenerate = () => {
    setStep(3)
    onGenerateToken({
      primary_protocol: primaryProtocol,
      enabled_protocols: [primaryProtocol],
      permissions: permissions.length ? permissions : ['system_metrics', 'inventory', 'filesystem'],
      expires_hours: expiresHours === 'never' ? 0 : expiresHours,
      target_os: targetOs,
    }, selectedWs)
  }

  const protocols = metadata.protocols ?? {}
  const perms = metadata.permissions ?? {}
  // Display order: PSAP first, then HTTP API, then SNMP
  const protocolOrder: Record<string, number> = { psap: 0, http_api: 1, snmp: 2 }
  const protocolEntriesSorted = Object.entries(protocols).sort(
    ([a], [b]) => (protocolOrder[a] ?? 99) - (protocolOrder[b] ?? 99)
  )

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
      <div className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl border border-white/10 bg-[#0f151d] shadow-xl">
        <div className="sticky top-0 flex items-center justify-between border-b border-white/10 bg-[#0f151d] px-6 py-4">
          <h2 className="text-lg font-semibold text-white">{t('agents.installTitle')}</h2>
          <button
            type="button"
            onClick={onClose}
            className="rounded p-1 text-white/60 hover:bg-white/10 hover:text-white"
          >
            ✕
          </button>
        </div>

        <div className="space-y-6 p-6">
          <div className="flex flex-wrap gap-2 text-[10px] uppercase tracking-wide text-white/40">
            {[t('agents.wizard.stepWorkspace'), t('agents.wizard.stepOs'), t('agents.wizard.stepToken'), t('agents.wizard.stepInstall'), t('agents.wizard.stepVerify')].map((label, i) => (
              <span key={label} className={step >= i + 1 ? 'text-sky-300' : ''}>{i + 1}. {label}</span>
            ))}
          </div>
          {!enrollmentResult ? (
            <>
              <section>
                <h3 className="mb-2 text-sm font-medium text-white/80">{t('agents.wizard.stepWorkspace')}</h3>
                <p className="mb-2 text-xs text-white/50">{t('agents.wizard.selectWorkspace')}</p>
                <select
                  value={selectedWs}
                  onChange={(e) => setSelectedWs(e.target.value)}
                  className="w-full rounded border border-white/20 bg-white/5 px-3 py-2 text-sm text-white"
                >
                  {workspaces.map((w) => (
                    <option key={w.id} value={String(w.id)}>{w.name}</option>
                  ))}
                </select>
              </section>
              <section>
                <h3 className="mb-2 text-sm font-medium text-white/80">{t('agents.osLabel')}</h3>
                <div className="flex flex-wrap gap-2">
                  {(['linux', 'windows', 'macos'] as const).map((os) => (
                    <button
                      key={os}
                      type="button"
                      onClick={() => setTargetOs(os)}
                      className={`rounded-lg border px-3 py-1.5 text-xs ${
                        targetOs === os
                          ? 'border-sky-500/50 bg-sky-500/20 text-sky-200'
                          : 'border-white/10 bg-white/5 text-white/70'
                      }`}
                    >
                      {t(`agents.os.${os}`)}
                    </button>
                  ))}
                </div>
              </section>
              <section>
                <h3 className="mb-2 text-sm font-medium text-white/80">{t('agents.protocolLabel')}</h3>
                <p className="mb-3 text-xs text-white/50">
                  Choose how the agent communicates. Quenyx Agent Protocol (PSAP) or HTTP API (push).
                </p>
                <p className="mb-3 rounded border border-sky-500/30 bg-sky-500/10 px-3 py-2 text-xs text-sky-200">
                  <strong>Current behavior:</strong> The agent always sends data to the platform over HTTPS (HTTP API push). No port needs to be open on the platform. PSAP (port 9444) is stored as your preference for when platform→agent pull is implemented; with PSAP, port 9444 would be opened on the <em>agent host</em>, not on this server.
                </p>
                <div className="space-y-2">
                  {protocolEntriesSorted.map(([key, info]) => (
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
            <>
              <EnrollmentResultView result={enrollmentResult} onClose={onClose} />
              <div className="rounded-lg border border-white/10 bg-white/5 p-4 text-sm">
                <h4 className="font-medium text-white/90">{t('agents.wizard.stepVerify')}</h4>
                <p className="mt-2 text-xs text-white/60">
                  {verifyStatus === 'waiting' && t('agents.wizard.verifyWaiting')}
                  {verifyStatus === 'timeout' && t('agents.wizard.verifyTimeout')}
                  {verifyStatus === 'success' && t('agents.wizard.verifySuccess')}
                </p>
              </div>
            </>
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
      <div className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm text-emerald-200">
        <strong>Token generated.</strong> Save it—it will not be shown again.{' '}
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
        <h3 className="mb-2 text-sm font-medium text-white/80">Command-line instructions</h3>
        <p className="mb-3 text-xs text-white/50">
          Copy the commands for your OS to download the agent and enroll.
        </p>
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
