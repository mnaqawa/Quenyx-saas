import { useState } from 'react'
import { useLanguage } from '../../i18n/LanguageContext'
import { aiWorkspaceService } from '../../services/aiWorkspaceService'
import { useAiResource, useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { AiError, AiView, Card } from '../../components/ai/workspace/shared'
import type { AiProvider } from '../../types/aiWorkspace'

/**
 * AI Provider Settings — per-workspace provider preferences. Secrets are write-only: the API never
 * returns them, only a "configured" indicator. Updates require admin + can_manage_providers.
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

  const startEdit = (p: AiProvider) => {
    setEditing(p.uuid)
    setModel(p.model ?? '')
    setApiKey('')
    setEnabled(p.enabled)
    setFormError(null)
  }

  const save = async (uuid: string) => {
    if (!workspaceUuid) return
    setSaving(true)
    setFormError(null)
    try {
      await aiWorkspaceService.updateProviderSettings(workspaceUuid, uuid, {
        enabled,
        model: model.trim() || null,
        ...(apiKey.trim() ? { api_key: apiKey.trim() } : {}),
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
        <div className="space-y-3">
          {res.providers.map((p) => (
            <Card key={p.uuid} className="space-y-3">
              <div className="flex items-center justify-between">
                <div>
                  <h3 className="text-sm font-semibold text-white">
                    {p.provider} {p.is_default ? <span className="text-xs text-sky-300">· {t('aiWorkspace.providers.default')}</span> : null}
                  </h3>
                  <p className="text-xs text-white/40">
                    {p.implemented ? t('aiWorkspace.providers.implemented') : t('aiWorkspace.providers.declared')} ·{' '}
                    {p.secret_configured ? t('aiWorkspace.providers.secretSet') : t('aiWorkspace.providers.noSecret')}
                  </p>
                </div>
                <button onClick={() => startEdit(p)} className="text-xs text-sky-300 hover:underline">
                  {t('aiWorkspace.common.edit')}
                </button>
              </div>

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
                  <div className="flex gap-2">
                    <button
                      onClick={() => void save(p.uuid)}
                      disabled={saving}
                      className="rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white hover:bg-sky-400 disabled:opacity-40"
                    >
                      {saving ? t('aiWorkspace.common.saving') : t('aiWorkspace.common.save')}
                    </button>
                    <button onClick={() => setEditing(null)} className="rounded-full border border-white/15 px-4 py-2 text-xs font-semibold text-white/70 hover:bg-white/10">
                      {t('aiWorkspace.common.cancel')}
                    </button>
                  </div>
                </div>
              ) : null}
            </Card>
          ))}
        </div>
      )}
    </AiView>
  )
}
