import { useState, type ReactNode } from 'react'
import { useLanguage } from '../../i18n/LanguageContext'
import { aiWorkspaceService } from '../../services/aiWorkspaceService'
import { useAiResource, useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { AiError, AiView, Card } from '../../components/ai/workspace/shared'
import { formatDateTime } from '../../components/ai/workspace/format'
import type { AiProvider, AiProviderTestResult } from '../../types/aiWorkspace'

/**
 * Quenyx AI — enterprise provider governance (v1.0.0).
 *
 * Lists the platform provider catalog merged with this workspace's saved preferences. Secrets are
 * write-only (the API never returns them, only a "configured" indicator). Providers without a live
 * execution adapter are shown honestly as "catalog only". Every mutation (settings, enable/disable,
 * clear secret, test connection) is audited server-side. Updates require admin + can_manage_providers.
 */
export default function AiProviders() {
  const { t } = useLanguage()
  const { workspaceUuid, hasWorkspace } = useAiWorkspaceUuid()
  const { data, loading, error, reload } = useAiResource((uuid) => aiWorkspaceService.listProviders(uuid))

  const [editing, setEditing] = useState<string | null>(null)
  const [model, setModel] = useState('')
  const [apiKey, setApiKey] = useState('')
  const [enabled, setEnabled] = useState(true)
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const [busyUuid, setBusyUuid] = useState<string | null>(null)
  const [testing, setTesting] = useState<string | null>(null)
  const [tests, setTests] = useState<Record<string, AiProviderTestResult | { error: string }>>({})

  const startEdit = (p: AiProvider) => {
    setEditing(p.uuid)
    setModel(p.model ?? '')
    setApiKey('')
    setEnabled(p.enabled)
    setFormError(null)
  }

  const save = async (uuid: string, clearSecret = false) => {
    if (!workspaceUuid) return
    setSaving(true)
    setFormError(null)
    try {
      await aiWorkspaceService.updateProviderSettings(workspaceUuid, uuid, {
        enabled,
        model: model.trim() || null,
        ...(apiKey.trim() ? { api_key: apiKey.trim() } : {}),
        ...(clearSecret ? { clear_secrets: true } : {}),
      })
      setEditing(null)
      setApiKey('')
      reload()
    } catch (err: unknown) {
      setFormError(err instanceof Error ? err.message : t('aiWorkspace.common.saveError'))
    } finally {
      setSaving(false)
    }
  }

  const toggleEnabled = async (p: AiProvider) => {
    if (!workspaceUuid) return
    setBusyUuid(p.uuid)
    try {
      await aiWorkspaceService.updateProviderSettings(workspaceUuid, p.uuid, { enabled: !p.enabled })
      reload()
    } catch {
      /* surfaced on next load */
    } finally {
      setBusyUuid(null)
    }
  }

  const runTest = async (p: AiProvider) => {
    if (!workspaceUuid) return
    setTesting(p.uuid)
    try {
      const res = await aiWorkspaceService.testProvider(workspaceUuid, p.uuid)
      setTests((prev) => ({ ...prev, [p.uuid]: res }))
    } catch (err: unknown) {
      setTests((prev) => ({ ...prev, [p.uuid]: { error: err instanceof Error ? err.message : t('aiWorkspace.providers.testError') } }))
    } finally {
      setTesting(null)
    }
  }

  return (
    <AiView
      hasWorkspace={hasWorkspace}
      loading={loading}
      error={error}
      data={data}
      onRetry={reload}
      isEmpty={(res) => res.providers.length === 0}
      emptyTitle={t('aiWorkspace.providers.emptyTitle')}
      emptyDescription={t('aiWorkspace.providers.emptyDescription')}
    >
      {(res) => (
        <div className="space-y-4">
          <p className="text-sm text-white/50">{t('aiWorkspace.providers.subtitle')}</p>

          <div className="grid gap-4 lg:grid-cols-2">
            {res.providers.map((p) => {
              const test = tests[p.uuid]
              return (
                <Card key={p.uuid} className="flex flex-col gap-3">
                  {/* Header */}
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <div className="flex flex-wrap items-center gap-2">
                        <h3 className="truncate text-sm font-semibold text-white">{p.label}</h3>
                        {p.is_default ? <Badge tone="sky">{t('aiWorkspace.providers.default')}</Badge> : null}
                      </div>
                      <p className="mt-0.5 font-mono text-[11px] text-white/40">{p.provider}</p>
                    </div>
                    <Badge tone="slate">{t(`aiWorkspace.providers.type.${p.type}`)}</Badge>
                  </div>

                  {/* Status badges */}
                  <div className="flex flex-wrap gap-1.5">
                    {p.executable ? (
                      <Badge tone="emerald">{t('aiWorkspace.providers.executable')}</Badge>
                    ) : (
                      <Badge tone="amber">{t('aiWorkspace.providers.notExecutable')}</Badge>
                    )}
                    <Badge tone={p.enabled ? 'emerald' : 'slate'}>
                      {p.enabled ? t('aiWorkspace.common.enabled') : t('aiWorkspace.common.disabled')}
                    </Badge>
                    <Badge tone={p.configured ? 'sky' : 'slate'}>
                      {p.configured ? t('aiWorkspace.providers.configured') : t('aiWorkspace.providers.unconfigured')}
                    </Badge>
                    <Badge tone={p.secret_configured ? 'emerald' : 'slate'}>
                      {p.secret_configured ? t('aiWorkspace.providers.secretSet') : t('aiWorkspace.providers.noSecret')}
                    </Badge>
                    {p.platform_configured ? (
                      <Badge tone="emerald">{t('aiWorkspace.providers.platformConfigured')}</Badge>
                    ) : null}
                  </div>

                  {/* Capabilities */}
                  {p.capabilities.length > 0 ? (
                    <div>
                      <p className="mb-1 text-[11px] uppercase tracking-wide text-white/40">{t('aiWorkspace.providers.capabilities')}</p>
                      <div className="flex flex-wrap gap-1">
                        {p.capabilities.map((c) => (
                          <span key={c} className="rounded-md bg-white/5 px-2 py-0.5 font-mono text-[10px] text-white/60">{c}</span>
                        ))}
                      </div>
                    </div>
                  ) : null}

                  {/* Metadata rows */}
                  <dl className="grid grid-cols-1 gap-1 text-xs">
                    {p.endpoint ? (
                      <MetaRow label={t('aiWorkspace.providers.endpoint')}>
                        <span className="font-mono text-white/70">{p.endpoint}</span>
                      </MetaRow>
                    ) : null}
                    {p.model ? (
                      <MetaRow label={t('aiWorkspace.providers.model')}>
                        <span className="font-mono text-white/70">{p.model}</span>
                      </MetaRow>
                    ) : null}
                    {p.updated_at ? (
                      <MetaRow label={t('aiWorkspace.providers.lastUpdated')}>
                        <span className="text-white/70">{formatDateTime(p.updated_at)}</span>
                      </MetaRow>
                    ) : null}
                  </dl>

                  {!p.executable ? (
                    <p className="rounded-lg border border-amber-400/20 bg-amber-400/5 px-3 py-2 text-[11px] text-amber-100/80">
                      {t('aiWorkspace.providers.notExecutableHint')}
                    </p>
                  ) : null}

                  {/* Test result */}
                  {test ? (
                    'error' in test ? (
                      <p className="text-xs text-rose-300">{test.error}</p>
                    ) : (
                      <p className={`text-xs ${test.ok ? 'text-emerald-300' : 'text-amber-300'}`}>
                        {test.ok ? t('aiWorkspace.providers.testOk') : t('aiWorkspace.providers.testFail')} · {test.status}
                        {test.message ? ` — ${test.message}` : ''}
                      </p>
                    )
                  ) : null}

                  {/* Actions */}
                  <div className="mt-auto flex flex-wrap gap-2 border-t border-white/10 pt-3">
                    <button onClick={() => startEdit(p)} className="rounded-full border border-white/15 px-3 py-1.5 text-xs font-semibold text-white/80 hover:bg-white/10">
                      {t('aiWorkspace.common.edit')}
                    </button>
                    <button
                      onClick={() => void toggleEnabled(p)}
                      disabled={busyUuid === p.uuid}
                      className="rounded-full border border-white/15 px-3 py-1.5 text-xs font-semibold text-white/80 hover:bg-white/10 disabled:opacity-40"
                    >
                      {p.enabled ? t('aiWorkspace.providers.disable') : t('aiWorkspace.providers.enable')}
                    </button>
                    <button
                      onClick={() => void runTest(p)}
                      disabled={testing === p.uuid}
                      className="rounded-full border border-white/15 px-3 py-1.5 text-xs font-semibold text-white/80 hover:bg-white/10 disabled:opacity-40"
                    >
                      {testing === p.uuid ? t('aiWorkspace.providers.testing') : t('aiWorkspace.providers.test')}
                    </button>
                    {p.docs_url ? (
                      <a href={p.docs_url} target="_blank" rel="noreferrer" className="rounded-full border border-white/15 px-3 py-1.5 text-xs font-semibold text-sky-300 hover:bg-white/10">
                        {t('aiWorkspace.providers.docs')}
                      </a>
                    ) : null}
                  </div>

                  {/* Edit form */}
                  {editing === p.uuid ? (
                    <div className="space-y-2 border-t border-white/10 pt-3">
                      {formError ? <AiError message={formError} /> : null}
                      <label className="flex items-center gap-2 text-xs text-white/70">
                        <input type="checkbox" checked={enabled} onChange={(e) => setEnabled(e.target.checked)} />
                        {t('aiWorkspace.providers.enabled')}
                      </label>
                      <input
                        value={model}
                        onChange={(e) => setModel(e.target.value)}
                        placeholder={t('aiWorkspace.providers.model')}
                        className="w-full rounded-lg border border-white/10 bg-[#0b0f14] px-3 py-2 text-sm text-white placeholder:text-white/30 focus:border-sky-400 focus:outline-none"
                      />
                      <input
                        type="password"
                        value={apiKey}
                        onChange={(e) => setApiKey(e.target.value)}
                        placeholder={t('aiWorkspace.providers.apiKeyPlaceholder')}
                        autoComplete="off"
                        className="w-full rounded-lg border border-white/10 bg-[#0b0f14] px-3 py-2 text-sm text-white placeholder:text-white/30 focus:border-sky-400 focus:outline-none"
                      />
                      <p className="text-[11px] text-white/30">{t('aiWorkspace.providers.secretHint')}</p>
                      <p className="text-[11px] text-white/30">{t('aiWorkspace.providers.defaultHint')}</p>
                      <div className="flex flex-wrap gap-2">
                        <button
                          onClick={() => void save(p.uuid)}
                          disabled={saving}
                          className="rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white hover:bg-sky-400 disabled:opacity-40"
                        >
                          {saving ? t('aiWorkspace.common.saving') : t('aiWorkspace.common.save')}
                        </button>
                        {p.secret_configured ? (
                          <button
                            onClick={() => void save(p.uuid, true)}
                            disabled={saving}
                            className="rounded-full border border-rose-400/40 px-4 py-2 text-xs font-semibold text-rose-200 hover:bg-rose-500/10 disabled:opacity-40"
                          >
                            {t('aiWorkspace.providers.clearSecret')}
                          </button>
                        ) : null}
                        <button onClick={() => setEditing(null)} className="rounded-full border border-white/15 px-4 py-2 text-xs font-semibold text-white/70 hover:bg-white/10">
                          {t('aiWorkspace.common.cancel')}
                        </button>
                      </div>
                    </div>
                  ) : null}
                </Card>
              )
            })}
          </div>
        </div>
      )}
    </AiView>
  )
}

function MetaRow({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-3">
      <dt className="text-white/40">{label}</dt>
      <dd className="min-w-0 truncate text-right">{children}</dd>
    </div>
  )
}

type BadgeTone = 'sky' | 'emerald' | 'amber' | 'slate'

function Badge({ tone, children }: { tone: BadgeTone; children: React.ReactNode }) {
  const tones: Record<BadgeTone, string> = {
    sky: 'border-sky-400/30 bg-sky-400/10 text-sky-200',
    emerald: 'border-emerald-400/30 bg-emerald-400/10 text-emerald-200',
    amber: 'border-amber-400/30 bg-amber-400/10 text-amber-100',
    slate: 'border-white/10 bg-white/5 text-white/50',
  }
  return <span className={`rounded-full border px-2 py-0.5 text-[10px] font-medium ${tones[tone]}`}>{children}</span>
}
