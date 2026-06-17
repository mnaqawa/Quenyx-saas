import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { PageHeader } from '../components/observe/PageHeader'
import { useLanguage } from '../i18n/LanguageContext'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'
import { billingService, type BillingIntegration, type BillingProviderType } from '../services/billingService'

const PROVIDERS: BillingProviderType[] = ['manual', 'aws', 'azure', 'oracle_cloud', 'gcp', 'custom']

export default function BillingPage() {
  const { t } = useLanguage()
  const { selectedWorkspaceId, selectedWorkspaceRole } = useWorkspaceContext()
  const wsId = selectedWorkspaceId ? Number(selectedWorkspaceId) : null
  const canEdit = selectedWorkspaceRole === 'owner' || selectedWorkspaceRole === 'admin'

  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [summary, setSummary] = useState<Awaited<ReturnType<typeof billingService.getSummary>> | null>(null)
  const [integrations, setIntegrations] = useState<BillingIntegration[]>([])
  const [provider, setProvider] = useState<BillingProviderType>('manual')
  const [contact, setContact] = useState('')
  const [saving, setSaving] = useState(false)

  const load = useCallback(() => {
    if (!wsId) return
    setLoading(true)
    setError(null)
    Promise.all([billingService.getSummary(wsId), billingService.getIntegrations(wsId)])
      .then(([sum, ints]) => {
        setSummary(sum)
        setIntegrations(ints)
        setContact(sum.billing_contact ?? '')
      })
      .catch((err: unknown) => {
        setError(err instanceof Error ? err.message : t('common.errorGeneric'))
      })
      .finally(() => setLoading(false))
  }, [t, wsId])

  useEffect(() => {
    load()
  }, [load])

  const handleSave = async () => {
    if (!wsId || !canEdit) return
    setSaving(true)
    setError(null)
    try {
      await billingService.saveIntegration(wsId, {
        provider_type: provider,
        billing_contact: contact || undefined,
        config: provider === 'manual' || provider === 'custom' ? { note: 'configured_via_ui' } : {},
      })
      load()
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : t('billing.saveError'))
    } finally {
      setSaving(false)
    }
  }

  if (!selectedWorkspaceId) {
    return (
      <div className="space-y-6">
        <PageHeader title={t('billing.title')} subtitle={t('billing.subtitle')} />
        <p className="text-sm text-white/60">{t('billing.selectWorkspace')}</p>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('billing.title')}
        subtitle={t('billing.subtitle')}
        actions={
          <Link
            to="/subscriptions"
            className="rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white hover:bg-white/10"
          >
            {t('billing.viewSubscriptions')}
          </Link>
        }
      />

      {error ? (
        <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">{error}</div>
      ) : null}

      {loading ? (
        <div className="h-40 animate-pulse rounded-2xl border border-white/10 bg-white/5" />
      ) : (
        <>
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            <Section title={t('billing.currentPlan')}>
              <p className="text-lg font-semibold text-white">{summary?.current_plan.name ?? '—'}</p>
              <p className="mt-1 text-xs text-white/50">
                {summary?.current_plan.price_cents != null
                  ? `${(summary.current_plan.price_cents / 100).toFixed(2)} / ${summary.current_plan.interval ?? '—'}`
                  : t('billing.noPlanAmount')}
              </p>
            </Section>
            <Section title={t('billing.workspaceUsage')}>
              <p className="text-sm text-white/80">
                {t('billing.monitoredHosts')}: {summary?.workspace_usage.monitored_hosts ?? 0}
              </p>
              <p className="text-sm text-white/80">
                {t('billing.agents')}: {summary?.workspace_usage.agents ?? 0}
              </p>
            </Section>
            <Section title={t('billing.integrationStatus')}>
              <p className="text-sm font-medium text-white">
                {t(`billing.status.${summary?.billing_integration_status ?? 'not_connected'}`)}
              </p>
            </Section>
          </div>

          <Section title={t('billing.costDataSources')}>
            {integrations.length === 0 ? (
              <p className="text-sm text-white/55">{t('billing.noIntegrations')}</p>
            ) : (
              <ul className="space-y-2 text-sm text-white/75">
                {integrations.map((i) => (
                  <li key={i.id}>
                    {t(`billing.provider.${i.provider_type}`)} — {t(`billing.status.${i.status}`)}
                  </li>
                ))}
              </ul>
            )}
          </Section>

          {canEdit ? (
            <Section title={t('billing.configureIntegration')}>
              <div className="grid gap-3 md:grid-cols-2">
                <label className="flex flex-col gap-1 text-xs">
                  <span className="text-white/45">{t('billing.providerLabel')}</span>
                  <select
                    value={provider}
                    onChange={(e) => setProvider(e.target.value as BillingProviderType)}
                    className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white outline-none"
                  >
                    {PROVIDERS.map((p) => (
                      <option key={p} value={p} className="bg-[#0f151d]">
                        {t(`billing.provider.${p}`)}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="flex flex-col gap-1 text-xs">
                  <span className="text-white/45">{t('billing.contactLabel')}</span>
                  <input
                    type="email"
                    value={contact}
                    onChange={(e) => setContact(e.target.value)}
                    className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white outline-none"
                  />
                </label>
              </div>
              <p className="mt-2 text-xs text-white/45">{t('billing.cloudSyncNote')}</p>
              <button
                type="button"
                disabled={saving}
                onClick={handleSave}
                className="mt-4 rounded-lg bg-sky-500/80 px-4 py-2 text-xs font-semibold text-white hover:bg-sky-400 disabled:opacity-50"
              >
                {saving ? t('billing.saving') : t('billing.saveIntegration')}
              </button>
            </Section>
          ) : null}

          <Section title={t('billing.invoices')}>
            <p className="text-sm text-white/55">
              {summary?.invoices_available ? t('billing.invoicesSoon') : t('billing.invoicesUnavailable')}
            </p>
          </Section>
        </>
      )}
    </div>
  )
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
      <h3 className="mb-3 text-sm font-semibold text-white/80">{title}</h3>
      {children}
    </div>
  )
}
