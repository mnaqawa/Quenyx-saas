import { useState } from 'react'
import type { EnrollmentTokenResponse } from '../../services/agentService'
import { CopyButton } from './CopyButton'

export type VerifyStatus = 'idle' | 'waiting' | 'success' | 'timeout'

interface EnrollmentInstallPanelProps {
  enrollment: EnrollmentTokenResponse
  workspaceId: number
  gatewayUrl?: string | null
  downloadAvailable: boolean | null
  downloadMessage?: string | null
  verifyStatus: VerifyStatus
  connectedAgentHostname?: string | null
  onDone: () => void
}

export function EnrollmentInstallPanel({
  enrollment,
  workspaceId,
  gatewayUrl,
  downloadAvailable,
  downloadMessage,
  verifyStatus,
  connectedAgentHostname,
  onDone,
}: EnrollmentInstallPanelProps) {
  const [selectedOs, setSelectedOs] = useState<'linux' | 'windows' | 'macos'>('linux')
  const instructions = enrollment.install_instructions

  return (
    <div className="space-y-6">
      <div className="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-sm text-emerald-100">
        <p className="font-semibold text-emerald-200">Enrollment token generated</p>
        <p className="mt-1 text-emerald-100/80">
          Copy the token and install commands below. The token is shown once — save it before closing.
        </p>
        {enrollment.expires_at ? (
          <p className="mt-2 text-xs text-emerald-200/70">
            Expires: {new Date(enrollment.expires_at).toLocaleString()}
          </p>
        ) : (
          <p className="mt-2 text-xs text-emerald-200/70">This token does not expire.</p>
        )}
      </div>

      {downloadAvailable === false ? (
        <div className="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 text-sm text-amber-100">
          <p className="font-semibold text-amber-200">Agent binary not ready on server</p>
          <p className="mt-1 text-amber-100/80">
            {downloadMessage ??
              'The download URL will return a JSON error until an administrator builds the agent on the Quenyx server.'}
          </p>
          <p className="mt-2 font-mono text-xs text-amber-200/90">php artisan agent:build linux-amd64</p>
        </div>
      ) : null}

      <div>
        <label className="mb-2 block text-xs font-semibold uppercase tracking-wide text-white/50">
          Enrollment token
        </label>
        <div className="flex items-stretch gap-2">
          <code className="flex-1 overflow-x-auto rounded-lg border border-white/15 bg-black/40 px-3 py-2.5 font-mono text-sm text-white break-all">
            {enrollment.token}
          </code>
          <CopyButton text={enrollment.token} label="Copy token" />
        </div>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 text-xs">
        <div className="rounded-lg border border-white/10 bg-white/5 p-3">
          <p className="text-white/45">Workspace ID</p>
          <p className="mt-1 font-mono text-sm text-white">{workspaceId}</p>
        </div>
        <div className="rounded-lg border border-white/10 bg-white/5 p-3">
          <p className="text-white/45">Gateway URL</p>
          <div className="mt-1 flex items-center gap-2">
            <p className="flex-1 font-mono text-sm text-sky-200 break-all">
              {enrollment.gateway_url ?? gatewayUrl ?? '—'}
            </p>
            {enrollment.gateway_url ?? gatewayUrl ? (
              <CopyButton text={enrollment.gateway_url ?? gatewayUrl ?? ''} label="Copy" />
            ) : null}
          </div>
        </div>
      </div>

      <div>
        <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-white/50">Install on target host</p>
        <div className="mb-3 flex flex-wrap gap-2">
          {(['linux', 'windows', 'macos'] as const).map((os) => {
            const inst = instructions[os]
            if (!inst) return null
            return (
              <button
                key={os}
                type="button"
                onClick={() => setSelectedOs(os)}
                className={[
                  'rounded-lg px-3 py-1.5 text-xs font-medium transition',
                  selectedOs === os
                    ? 'bg-sky-500/30 text-sky-100 border border-sky-500/40'
                    : 'bg-white/5 text-white/60 border border-white/10 hover:text-white',
                ].join(' ')}
              >
                {inst.title}
              </button>
            )
          })}
        </div>

        {(['linux', 'windows', 'macos'] as const).map((os) => {
          if (os !== selectedOs) return null
          const inst = instructions[os]
          if (!inst) return null
          const steps = inst.steps.join('\n')
          return (
            <div key={os}>
              <h4 className="mb-2 text-sm font-medium text-white/90">{inst.title} commands</h4>
              <pre className="max-h-72 overflow-auto rounded-xl border border-white/10 bg-black/40 p-4 font-mono text-xs leading-relaxed text-white/90">
                {inst.steps.map((line, i) => (
                  <div
                    key={i}
                    className={
                      line.startsWith('ERROR') || line.includes('Verify')
                        ? 'text-amber-200/90'
                        : line === ''
                          ? 'h-2'
                          : ''
                    }
                  >
                    {line}
                  </div>
                ))}
              </pre>
              <div className="mt-3">
                <CopyButton text={steps} label={`Copy all ${inst.title} commands`} />
              </div>
            </div>
          )
        })}
      </div>

      <div className="rounded-xl border border-white/10 bg-white/5 p-4">
        <h4 className="text-sm font-semibold text-white">5. Verify &amp; finish</h4>
        <p className="mt-2 text-xs text-white/60">
          Run the commands on your target host, then confirm here. We poll for a new agent connection for up to 2 minutes.
        </p>
        <div className="mt-3">
          {verifyStatus === 'waiting' ? (
            <span className="inline-flex items-center gap-2 text-sm text-sky-200">
              <span className="h-2 w-2 animate-pulse rounded-full bg-sky-400" />
              Waiting for agent to connect…
            </span>
          ) : null}
          {verifyStatus === 'success' ? (
            <div className="space-y-1">
              <span className="text-sm font-medium text-emerald-300">Agent connected successfully.</span>
              {connectedAgentHostname ? (
                <p className="text-xs text-emerald-200/80">
                  Hostname: <span className="font-mono text-white">{connectedAgentHostname}</span>
                </p>
              ) : null}
              <p className="text-xs text-white/50">Click Done to open the Agents list.</p>
            </div>
          ) : null}
          {verifyStatus === 'timeout' ? (
            <span className="text-sm text-amber-200">
              No agent detected yet. If you finished install on the host, you can still close — check the Agents tab
              later.
            </span>
          ) : null}
        </div>
      </div>

      <div className="flex justify-end gap-3 border-t border-white/10 pt-4">
        <button
          type="button"
          onClick={onDone}
          className="rounded-lg border border-white/15 px-4 py-2 text-sm text-white/70 hover:bg-white/5"
        >
          {verifyStatus === 'success' ? 'Close' : 'Cancel'}
        </button>
        <button
          type="button"
          onClick={onDone}
          className="rounded-lg bg-sky-600 px-5 py-2 text-sm font-semibold text-white transition hover:bg-sky-500"
        >
          {verifyStatus === 'success' ? 'Done — view agents' : 'Done'}
        </button>
      </div>
    </div>
  )
}
