import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  integrationService,
  Integration,
} from '../services/integrationService'
import { useLanguage } from '../i18n/LanguageContext'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'
import Agents from './observe/Agents'

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
  const { selectedWorkspaceId } = useWorkspaceContext()
  const [integrations, setIntegrations] = useState<Integration[]>([])
  const [selectedIntegrationId, setSelectedIntegrationId] = useState<number | null>(null)
  type ConfigSettings = {
    endpoint: string
    api_key: string
    webhook_url: string
    primary_webhook: string
    backup_webhook: string
    topology_enabled: boolean
    topology_data: string
  }
  const [configSettings, setConfigSettings] = useState<ConfigSettings>({
    endpoint: '',
    api_key: '',
    webhook_url: '',
    primary_webhook: '',
    backup_webhook: '',
    topology_enabled: false,
    topology_data: '',
  })
  const [savingConfig, setSavingConfig] = useState(false)
  const [savingTopology, setSavingTopology] = useState(false)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const fetchData = async () => {
      setLoading(true)
      setError(null)
      try {
        if (!selectedWorkspaceId) {
          setIntegrations([])
          setLoading(false)
          return
        }
        const integrationData = await integrationService.listProjectIntegrations(Number(selectedWorkspaceId))
        setIntegrations(integrationData)
        setSelectedIntegrationId(integrationData[0]?.id ?? null)
      } catch (err) {
        const errorMessage = err instanceof Error ? err.message : 'Failed to load integrations'
        setError(errorMessage.includes('404') || errorMessage.includes('not found')
          ? `Workspace integrations not available. Please ensure the workspace exists and you have access.`
          : errorMessage)
      } finally {
        setLoading(false)
      }
    }

    fetchData()
  }, [selectedWorkspaceId])

  useEffect(() => {
    const fetchConfiguration = async () => {
      if (!selectedWorkspaceId || !selectedIntegrationId) {
        return
      }
      const data = await integrationService.getProjectIntegrationConfiguration(
        Number(selectedWorkspaceId),
        selectedIntegrationId
      )
      const settings = (data.settings ?? {}) as Record<string, unknown>
      const topo = settings.topology_data
      const topologyDataStr =
        typeof topo === 'object' && topo !== null
          ? JSON.stringify(topo as object, null, 2)
          : typeof topo === 'string'
            ? topo
            : ''
      setConfigSettings({
        endpoint: (settings.endpoint as string) ?? '',
        api_key: (settings.api_key as string) ?? '',
        webhook_url: (settings.webhook_url as string) ?? '',
        primary_webhook: (settings.primary_webhook as string) ?? '',
        backup_webhook: (settings.backup_webhook as string) ?? '',
        topology_enabled: settings.topology_enabled === true || settings.topology_enabled === 'true',
        topology_data: topologyDataStr,
      } as ConfigSettings)
    }
    fetchConfiguration()
  }, [selectedWorkspaceId, selectedIntegrationId])

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
    <div className="space-y-8" data-tour="tour-integrations">
      <div className="space-y-1 text-center">
        <h1 className="text-2xl font-semibold text-white">{t('integrations.title')}</h1>
        <p className="text-sm text-white/60">{t('integrations.subtitle')}</p>
      </div>

      <section className="space-y-4">
        <h2 className="text-sm font-semibold text-white">Agents</h2>
        <p className="text-xs text-white/60">
          Install agents on servers and workstations for cross-network monitoring, asset discovery (ShieldInventory),
          vulnerability assessment, and QynSight. Works across firewalls—only outbound HTTPS required.
        </p>
        <Agents embedded />
      </section>

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
        <h2 className="text-sm font-semibold text-white">Infrastructure Map (Observe)</h2>
        <p className="text-xs text-white/60">
          Use an external integration to feed nodes and connections into the{' '}
          <Link to={selectedWorkspaceId ? `/app/workspaces/${selectedWorkspaceId}/observe/infrastructure-map` : '#'} className="text-sky-300 hover:underline">
            Infrastructure Map
          </Link>
          . Enable below and paste topology JSON to merge with Observe data.
        </p>
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <div className="flex items-center gap-3">
            <input
              type="checkbox"
              id="topology_enabled"
              checked={configSettings.topology_enabled === true}
              onChange={(e) =>
                setConfigSettings((prev) => ({ ...prev, topology_enabled: e.target.checked }))
              }
              className="rounded border-white/30 bg-white/10 text-sky-500 focus:ring-sky-500/50"
            />
            <label htmlFor="topology_enabled" className="text-sm font-medium">
              Feed Infrastructure Map with topology from this integration
            </label>
          </div>
          <div className="mt-4">
            <label className="text-[10px] uppercase tracking-wide text-white/40">Topology JSON (nodes &amp; connections)</label>
            <textarea
              value={typeof configSettings.topology_data === 'string' ? configSettings.topology_data : ''}
              onChange={(e) =>
                setConfigSettings((prev) => ({ ...prev, topology_data: e.target.value }))
              }
              placeholder='{"nodes":[{"id":"ext-1","name":"External","type":"host","address":"10.0.0.1"}],"connections":[{"source":"External","destination":"Monitoring","type":"external","status":"Online"}]}'
              rows={6}
              className="mt-1 w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 font-mono text-[11px] text-white placeholder-white/40 focus:border-sky-500/50 focus:outline-none"
            />
          </div>
          <button
            type="button"
            onClick={async () => {
              if (!selectedWorkspaceId || !selectedIntegrationId) return
              setSavingTopology(true)
              try {
                const raw = typeof configSettings.topology_data === 'string' ? configSettings.topology_data : '{}'
                let topologyData: { nodes?: unknown[]; connections?: unknown[] } = {}
                try {
                  if (raw.trim()) topologyData = JSON.parse(raw) as { nodes?: unknown[]; connections?: unknown[] }
                } catch {
                  // leave empty
                }
                await integrationService.updateProjectIntegrationConfiguration(
                  Number(selectedWorkspaceId),
                  selectedIntegrationId,
                  {
                    ...configSettings,
                    topology_enabled: configSettings.topology_enabled,
                    topology_data: topologyData,
                  } as Record<string, unknown>
                )
              } finally {
                setSavingTopology(false)
              }
            }}
            className="mt-4 rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-400"
          >
            {savingTopology ? 'Saving…' : 'Save topology'}
          </button>
        </div>
      </section>

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
                if (!selectedWorkspaceId || !selectedIntegrationId) return
                setSavingConfig(true)
                await integrationService.updateProjectIntegrationConfiguration(
                  Number(selectedWorkspaceId),
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
            <p className="mt-2 text-[10px] text-sky-200/80">
              Use webhooks to send Observe alerts (e.g. critical service down) to Slack, Teams, or email.
            </p>
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
                if (!selectedWorkspaceId || !selectedIntegrationId) return
                setSavingConfig(true)
                await integrationService.updateProjectIntegrationConfiguration(
                  Number(selectedWorkspaceId),
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
