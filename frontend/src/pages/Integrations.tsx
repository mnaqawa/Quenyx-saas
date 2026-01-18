import { useEffect, useMemo, useState } from 'react'
import {
  integrationService,
  Integration,
} from '../services/integrationService'
import { useLanguage } from '../i18n/LanguageContext'
import { useProjectContext } from '../projects/ProjectContext'

const statusStyles: Record<string, string> = {
  connected: 'bg-emerald-500/20 text-emerald-200 border-emerald-500/30',
  configured: 'bg-sky-500/20 text-sky-200 border-sky-500/30',
  disconnected: 'bg-white/10 text-white/60 border-white/10',
}

const statusLabels: Record<string, string> = {
  connected: 'Connected',
  configured: 'Configured',
  disconnected: 'Disconnected',
}

function Integrations() {
  const { t } = useLanguage()
  const { selectedProjectId } = useProjectContext()
  const [integrations, setIntegrations] = useState<Integration[]>([])
  const [selectedIntegrationId, setSelectedIntegrationId] = useState<number | null>(null)
  const [configSettings, setConfigSettings] = useState<Record<string, string>>({})
  const [savingConfig, setSavingConfig] = useState(false)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const fetchData = async () => {
      try {
        if (!selectedProjectId) {
          setIntegrations([])
          return
        }
        const integrationData = await integrationService.listProjectIntegrations(selectedProjectId)
        setIntegrations(integrationData)
        setSelectedIntegrationId(integrationData[0]?.id ?? null)
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load integrations')
      } finally {
        setLoading(false)
      }
    }

    fetchData()
  }, [selectedProjectId])

  useEffect(() => {
    const fetchConfiguration = async () => {
      if (!selectedProjectId || !selectedIntegrationId) {
        return
      }
      const data = await integrationService.getProjectIntegrationConfiguration(
        selectedProjectId,
        selectedIntegrationId
      )
      const settings = (data.settings ?? {}) as Record<string, string>
      setConfigSettings({
        endpoint: settings.endpoint ?? '',
        api_key: settings.api_key ?? '',
        webhook_url: settings.webhook_url ?? '',
        primary_webhook: settings.primary_webhook ?? '',
        backup_webhook: settings.backup_webhook ?? '',
      })
    }
    fetchConfiguration()
  }, [selectedProjectId, selectedIntegrationId])

  const activeIntegration = useMemo(() => {
    return integrations.find((integration) => integration.id === selectedIntegrationId) ?? null
  }, [integrations, selectedIntegrationId])

  if (loading) {
    return <div className="text-sm text-white/60">{t('common.loadingIntegrations')}</div>
  }

  if (error) {
    return (
      <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
        {error}
      </div>
    )
  }

  return (
    <div className="space-y-8">
      <div className="space-y-1 text-center">
        <h1 className="text-2xl font-semibold text-white">{t('integrations.title')}</h1>
        <p className="text-sm text-white/60">{t('integrations.subtitle')}</p>
      </div>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {integrations.map((integration) => (
          <div
            key={integration.id}
            className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white"
          >
            <div className="flex items-start justify-between gap-3">
              <div>
                <h3 className="text-sm font-semibold">{integration.name}</h3>
                <p className="mt-1 text-xs text-white/60">{integration.description}</p>
              </div>
              <span
                className={`rounded-full border px-3 py-1 text-[10px] font-semibold ${
                  statusStyles[integration.configured ? 'configured' : integration.status]
                }`}
              >
                {integration.configured ? statusLabels.configured : statusLabels[integration.status]}
              </span>
            </div>

            <div className="mt-4 space-y-2 text-xs text-white/70">
              <div className="flex items-center gap-2">
                <span className="text-[10px] uppercase tracking-wide text-white/40">{t('integrations.status')}</span>
                <span className="text-white/70">
                  {integration.configured ? statusLabels.configured : statusLabels[integration.status]}
                </span>
              </div>
              <div>
                <p className="text-[10px] uppercase tracking-wide text-white/40">{t('integrations.endpoint')}</p>
                <div className="mt-1 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-[11px] text-white/70">
                  {integration.endpoint}
                </div>
              </div>
            </div>

            <div className="mt-4 flex items-center gap-2">
              <button
                type="button"
                onClick={() => setSelectedIntegrationId(integration.id)}
                className="flex-1 rounded-full border border-white/10 bg-white/5 px-3 py-2 text-xs font-semibold text-white transition hover:bg-white/10"
              >
                {integration.primary_action}
              </button>
              {integration.secondary_action ? (
                <button
                  type="button"
                  className="rounded-full border border-white/10 px-3 py-2 text-xs font-semibold text-white/70 transition hover:bg-white/10"
                >
                  {integration.secondary_action}
                </button>
              ) : null}
            </div>
          </div>
        ))}
      </div>

      <div className="rounded-2xl border border-white/10 bg-[#0f151d] px-6 py-10 text-center text-white">
        <div className="mx-auto flex h-10 w-10 items-center justify-center rounded-full border border-white/10 text-lg">
          +
        </div>
        <h2 className="mt-4 text-sm font-semibold">{t('integrations.addTitle')}</h2>
        <p className="mt-2 text-xs text-white/60">
          {t('integrations.addDesc')}
        </p>
        <button
          type="button"
          className="mt-4 rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-400"
        >
          {t('integrations.browse')}
        </button>
      </div>

      <section className="space-y-4">
        <h2 className="text-sm font-semibold text-white">{t('integrations.apiConfig')}</h2>
        <div className="grid gap-4 md:grid-cols-2">
          <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
            <h3 className="text-sm font-semibold">{t('integrations.apiKeys')}</h3>
            <p className="mt-1 text-xs text-white/60">{t('integrations.apiKeysDesc')}</p>
            <div className="mt-3 text-xs text-white/60">
              {activeIntegration ? `Integration: ${activeIntegration.name}` : 'Select an integration'}
            </div>
            <div className="mt-4 space-y-3 text-xs text-white/70">
              <div>
                <p className="text-[10px] uppercase tracking-wide text-white/40">API Key</p>
                <input
                  value={configSettings.api_key ?? ''}
                  onChange={(event) =>
                    setConfigSettings((prev) => ({ ...prev, api_key: event.target.value }))
                  }
                  className="mt-1 w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs text-white"
                />
              </div>
              <div>
                <p className="text-[10px] uppercase tracking-wide text-white/40">Webhook URL</p>
                <input
                  value={configSettings.webhook_url ?? ''}
                  onChange={(event) =>
                    setConfigSettings((prev) => ({ ...prev, webhook_url: event.target.value }))
                  }
                  className="mt-1 w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs text-white"
                />
              </div>
            </div>
            <button
              type="button"
              onClick={async () => {
                if (!selectedProjectId || !selectedIntegrationId) return
                setSavingConfig(true)
                await integrationService.updateProjectIntegrationConfiguration(
                  selectedProjectId,
                  selectedIntegrationId,
                  configSettings
                )
                setSavingConfig(false)
              }}
              className="mt-4 w-full rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-400"
            >
              {savingConfig ? t('integrations.saving') : t('integrations.updateKeys')}
            </button>
          </div>

          <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
            <h3 className="text-sm font-semibold">{t('integrations.webhooks')}</h3>
            <p className="mt-1 text-xs text-white/60">{t('integrations.webhooksDesc')}</p>
            <div className="mt-4 space-y-3 text-xs text-white/70">
              <div>
                <p className="text-[10px] uppercase tracking-wide text-white/40">Primary Webhook URL</p>
                <input
                  value={configSettings.primary_webhook ?? ''}
                  onChange={(event) =>
                    setConfigSettings((prev) => ({ ...prev, primary_webhook: event.target.value }))
                  }
                  className="mt-1 w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs text-white"
                />
              </div>
              <div>
                <p className="text-[10px] uppercase tracking-wide text-white/40">Backup Webhook URL</p>
                <input
                  value={configSettings.backup_webhook ?? ''}
                  onChange={(event) =>
                    setConfigSettings((prev) => ({ ...prev, backup_webhook: event.target.value }))
                  }
                  className="mt-1 w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs text-white"
                />
              </div>
            </div>
            <button
              type="button"
              onClick={async () => {
                if (!selectedProjectId || !selectedIntegrationId) return
                setSavingConfig(true)
                await integrationService.updateProjectIntegrationConfiguration(
                  selectedProjectId,
                  selectedIntegrationId,
                  configSettings
                )
                setSavingConfig(false)
              }}
              className="mt-4 w-full rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-400"
            >
              {savingConfig ? t('integrations.saving') : t('integrations.saveWebhooks')}
            </button>
          </div>
        </div>
      </section>
    </div>
  )
}

export default Integrations
