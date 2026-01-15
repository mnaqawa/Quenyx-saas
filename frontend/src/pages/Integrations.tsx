import { useEffect, useMemo, useState } from 'react'
import {
  integrationService,
  Integration,
  IntegrationConfiguration,
} from '../services/integrationService'

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
  const [integrations, setIntegrations] = useState<Integration[]>([])
  const [configuration, setConfiguration] = useState<IntegrationConfiguration | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const fetchData = async () => {
      try {
        const [integrationData, configData] = await Promise.all([
          integrationService.getIntegrations(),
          integrationService.getConfiguration(),
        ])
        setIntegrations(integrationData)
        setConfiguration(configData)
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load integrations')
      } finally {
        setLoading(false)
      }
    }

    fetchData()
  }, [])

  const apiKeys = configuration?.api_keys
  const webhookEndpoints = configuration?.webhook_endpoints
  const hasData = integrations.length > 0

  if (loading) {
    return <div className="text-sm text-white/60">Loading integrations...</div>
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
        <h1 className="text-2xl font-semibold text-white">Integrations</h1>
        <p className="text-sm text-white/60">
          Connect external services and tools to enhance your PortShield SaaS
        </p>
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
                  statusStyles[integration.status]
                }`}
              >
                {statusLabels[integration.status]}
              </span>
            </div>

            <div className="mt-4 space-y-2 text-xs text-white/70">
              <div className="flex items-center gap-2">
                <span className="text-[10px] uppercase tracking-wide text-white/40">Status</span>
                <span className="text-white/70">{statusLabels[integration.status]}</span>
              </div>
              <div>
                <p className="text-[10px] uppercase tracking-wide text-white/40">Endpoint</p>
                <div className="mt-1 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-[11px] text-white/70">
                  {integration.endpoint}
                </div>
              </div>
            </div>

            <div className="mt-4 flex items-center gap-2">
              <button
                type="button"
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
        <h2 className="mt-4 text-sm font-semibold">Add New Integration</h2>
        <p className="mt-2 text-xs text-white/60">
          Connect additional services to expand your operational capabilities
        </p>
        <button
          type="button"
          className="mt-4 rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-400"
        >
          Browse Available Integrations
        </button>
      </div>

      <section className="space-y-4">
        <h2 className="text-sm font-semibold text-white">API Configuration</h2>
        <div className="grid gap-4 md:grid-cols-2">
          <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
            <h3 className="text-sm font-semibold">API Keys</h3>
            <p className="mt-1 text-xs text-white/60">Manage your API keys for external service integrations</p>
            <div className="mt-4 space-y-3 text-xs text-white/70">
              <div>
                <p className="text-[10px] uppercase tracking-wide text-white/40">GitHub Personal Access Token</p>
                <div className="mt-1 rounded-lg border border-white/10 bg-white/5 px-3 py-2">
                  {apiKeys?.github_pat ?? 'Not configured'}
                </div>
              </div>
              <div>
                <p className="text-[10px] uppercase tracking-wide text-white/40">Slack Webhook URL</p>
                <div className="mt-1 rounded-lg border border-white/10 bg-white/5 px-3 py-2">
                  {apiKeys?.slack_webhook_url ?? 'Not configured'}
                </div>
              </div>
            </div>
            <button
              type="button"
              className="mt-4 w-full rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-400"
            >
              Update API Keys
            </button>
          </div>

          <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
            <h3 className="text-sm font-semibold">Webhook Endpoints</h3>
            <p className="mt-1 text-xs text-white/60">Configure webhook endpoints for real-time notifications</p>
            <div className="mt-4 space-y-3 text-xs text-white/70">
              <div>
                <p className="text-[10px] uppercase tracking-wide text-white/40">Primary Webhook URL</p>
                <div className="mt-1 rounded-lg border border-white/10 bg-white/5 px-3 py-2">
                  {webhookEndpoints?.primary ?? 'Not configured'}
                </div>
              </div>
              <div>
                <p className="text-[10px] uppercase tracking-wide text-white/40">Backup Webhook URL</p>
                <div className="mt-1 rounded-lg border border-white/10 bg-white/5 px-3 py-2">
                  {webhookEndpoints?.backup ?? 'Not configured'}
                </div>
              </div>
            </div>
            <button
              type="button"
              className="mt-4 w-full rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-400"
            >
              Save Webhook Configuration
            </button>
          </div>
        </div>
      </section>
    </div>
  )
}

export default Integrations
