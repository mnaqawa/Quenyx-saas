import { useState, useEffect, useCallback, useMemo, type ComponentProps, type ReactNode } from 'react'
import { useAlertRules, useAlertSummary } from '../../hooks/useObserveData'
import { StatCard } from '../../components/observe/StatCard'
import { PageHeader } from '../../components/observe/PageHeader'
import { ObservePageToolbar } from '../../components/observe/ObservePageToolbar'
import { Tabs } from '../../components/observe/Tabs'
import { StatusBadge } from '../../components/observe/StatusBadge'
import { AlertHistoryFilterBar } from '../../components/observe/AlertHistoryFilterBar'
import { toApiDateTime } from '../../components/observe/dateTimeApi'
import { useLanguage } from '../../i18n/LanguageContext'
import { useWorkspaceContext } from '../../workspaces/WorkspaceContext'
import { observeService } from '../../services/observeService'
import { useObserveAutoRefresh } from '../../hooks/useObserveAutoRefresh'
import { formatReadableAlertCondition } from '../../lib/alertConditionLabels'
import { useAiAgentAvailable } from '../../hooks/useAiAgentAvailable'
import { AIAgentDrawer } from '../../components/ai/AIAgentDrawer'
import type { AIAgentSeed } from '../../types/aiAgent'
import type {
  AlertHistoryEvent,
  AlertHistoryFilters,
  CreateAlertRulePayload,
  ObserveTargetHostOption,
  AlertRule,
} from '../../types/observe'

const ALERT_METRIC_OPTIONS = [
  'cpu',
  'memory',
  'disk',
  'load',
  'network',
  'host_unreachable',
  'service_critical',
  'service_warning',
  'capacity_risk_score',
  'cpu_runway_days',
  'memory_runway_days',
  'storage_runway_days',
] as const

const ALERT_OPERATORS = ['>', '>=', '<', '<=', '=', '!='] as const

const inputClass = 'w-full rounded border border-white/20 bg-white/5 px-3 py-2 text-sm text-white'

type BadgeStatus = ComponentProps<typeof StatusBadge>['status']

function severityToBadgeStatus(severity: string): BadgeStatus {
  if (severity === 'critical') return 'critical'
  if (severity === 'warning') return 'warning'
  if (severity === 'info') return 'connected'
  return 'degraded'
}

function alertSeverityBorderClass(severity: string, status?: string): string {
  if (status === 'resolved') return 'border-s-emerald-500'
  if (severity === 'critical') return 'border-s-rose-500'
  if (severity === 'warning') return 'border-s-amber-500'
  if (severity === 'info') return 'border-s-sky-500'
  return 'border-s-white/20'
}

function alertStatusToBadgeStatus(status: string): BadgeStatus {
  if (status === 'open' || status === 'active') return 'critical'
  if (status === 'acknowledged') return 'warning'
  if (status === 'resolved') return 'healthy'
  return 'degraded'
}

function alertStatusLabel(status: string, t: (key: string) => string): string {
  if (status === 'open' || status === 'active') return t('alerts.status.open')
  if (status === 'acknowledged') return t('alerts.status.acknowledged')
  if (status === 'resolved') return t('alerts.status.resolved')
  return status
}

function filtersForApi(filters: AlertHistoryFilters): AlertHistoryFilters {
  return {
    ...filters,
    date_from: toApiDateTime(filters.date_from),
    date_to: toApiDateTime(filters.date_to),
  }
}

function ruleConditionText(rule: AlertRule, t: (key: string) => string): string {
  return formatReadableAlertCondition(rule, t)
}

function historyConditionText(
  event: AlertHistoryEvent,
  rules: AlertRule[],
  t: (key: string) => string,
): string | null {
  const rule = event.rule_id ? rules.find((r) => r.id === event.rule_id) : undefined
  if (rule) return ruleConditionText(rule, t)
  const meta = event.metadata as { metric_condition?: string; operator?: string; threshold_value?: number; condition?: string } | null
  if (meta?.metric_condition && meta?.operator) {
    return formatReadableAlertCondition(meta, t)
  }
  if (meta?.condition) return formatReadableAlertCondition({ condition: meta.condition }, t)
  return null
}

export default function AlertManagement() {
  const { t } = useLanguage()
  const { selectedWorkspaceId, selectedWorkspaceRole } = useWorkspaceContext()
  const [activeTab, setActiveTab] = useState('rules')
  const [history, setHistory] = useState<AlertHistoryEvent[]>([])
  const [tabLoading, setTabLoading] = useState(false)
  const [createOpen, setCreateOpen] = useState(false)
  const [refreshKey, setRefreshKey] = useState(0)
  const [detailEvent, setDetailEvent] = useState<AlertHistoryEvent | null>(null)
  const [aiOpen, setAiOpen] = useState(false)
  const [aiSeed, setAiSeed] = useState<AIAgentSeed | null>(null)
  const aiAvailable = useAiAgentAvailable(selectedWorkspaceId)
  const [historyFilters, setHistoryFilters] = useState<AlertHistoryFilters>({})
  const [appliedHistoryFilters, setAppliedHistoryFilters] = useState<AlertHistoryFilters>({})
  const { rules, loading: rulesLoading } = useAlertRules(refreshKey)
  const { summary, loading: summaryLoading } = useAlertSummary(refreshKey)

  const canEdit = selectedWorkspaceRole === 'owner' || selectedWorkspaceRole === 'admin'
  const canAcknowledge =
    selectedWorkspaceRole === 'owner' ||
    selectedWorkspaceRole === 'admin' ||
    selectedWorkspaceRole === 'member'
  const safeRules = Array.isArray(rules) ? rules : []

  const loadTabData = useCallback(async () => {
    if (!selectedWorkspaceId) return
    setTabLoading(true)
    try {
      if (activeTab === 'history') {
        const events = await observeService.getAlertHistory(
          Number(selectedWorkspaceId),
          filtersForApi(appliedHistoryFilters)
        )
        setHistory(Array.isArray(events) ? events : [])
      }
    } catch {
      if (activeTab === 'history') setHistory([])
    } finally {
      setTabLoading(false)
    }
  }, [activeTab, selectedWorkspaceId, appliedHistoryFilters])

  const refreshAll = useCallback(() => {
    setRefreshKey((k) => k + 1)
  }, [])

  const {
    interval,
    setInterval,
    markUpdated,
    refreshNow,
    secondsAgo,
  } = useObserveAutoRefresh(refreshAll, !!selectedWorkspaceId)

  useEffect(() => {
    if (refreshKey > 0) markUpdated()
  }, [refreshKey, markUpdated])

  useEffect(() => {
    if (activeTab === 'history') {
      loadTabData()
    }
  }, [activeTab, loadTabData, refreshKey])

  const handleToggle = async (ruleId: string) => {
    if (!selectedWorkspaceId || !canEdit) return
    await observeService.toggleAlertRule(Number(selectedWorkspaceId), ruleId)
    setRefreshKey((k) => k + 1)
  }

  const handleDelete = async (ruleId: string) => {
    if (!selectedWorkspaceId || !canEdit || !confirm(t('alerts.deleteConfirm'))) return
    await observeService.deleteAlertRule(Number(selectedWorkspaceId), ruleId)
    setRefreshKey((k) => k + 1)
  }

  const handleAcknowledge = async (eventId: string) => {
    if (!selectedWorkspaceId || !canAcknowledge) return
    await observeService.acknowledgeAlertEvent(Number(selectedWorkspaceId), eventId)
    setRefreshKey((k) => k + 1)
    if (detailEvent?.id === eventId) {
      setDetailEvent(null)
    }
  }

  if (rulesLoading || summaryLoading) {
    return <div className="text-sm text-white/60">{t('agents.loading')}</div>
  }

  const tabs = [
    { id: 'rules', label: t('alerts.tab.rules') },
    { id: 'history', label: t('alerts.tab.history') },
  ]

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('alerts.title')}
        subtitle={t('alerts.subtitle')}
        actions={
          <>
            <ObservePageToolbar
              interval={interval}
              onIntervalChange={setInterval}
              secondsAgo={secondsAgo}
              onRefresh={() => {
                refreshAll()
                refreshNow()
              }}
              refreshing={rulesLoading || summaryLoading || tabLoading}
            />
            {canEdit ? (
              <button
                type="button"
                onClick={() => setCreateOpen(true)}
                className="rounded-lg bg-sky-600 px-4 py-1.5 text-xs font-semibold text-white hover:bg-sky-500"
              >
                {t('alerts.createRule')}
              </button>
            ) : null}
          </>
        }
      />

      <div className="grid gap-4 md:grid-cols-4">
        <StatCard
          title={t('alerts.card.active')}
          value={String(summary?.activeAlerts?.total ?? 0)}
          detail={`${summary?.activeAlerts?.critical ?? 0} ${t('alerts.critical')}, ${summary?.activeAlerts?.warning ?? 0} ${t('alerts.warning')}`}
        />
        <StatCard
          title={t('alerts.card.critical')}
          value={String(summary?.criticalAlerts ?? summary?.activeAlerts?.critical ?? 0)}
          detail={t('alerts.critical')}
        />
        <StatCard
          title={t('alerts.card.acknowledged')}
          value={String(summary?.acknowledgedAlerts ?? 0)}
          detail={t('alerts.status.acknowledged')}
        />
        <StatCard
          title={t('alerts.card.resolvedToday')}
          value={String(summary?.resolvedToday ?? 0)}
          detail={t('alerts.status.resolved')}
        />
      </div>

      <Tabs tabs={tabs} activeTab={activeTab} onTabChange={setActiveTab} />

      {activeTab === 'rules' && (
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          {safeRules.length === 0 ? (
            <div className="py-10 text-center text-sm text-white/60">{t('alerts.rulesEmpty')}</div>
          ) : (
            <div className="space-y-3">
              {safeRules.map((rule) => (
                <div
                  key={rule.id}
                  className={`flex flex-wrap items-center gap-4 rounded-lg border border-white/5 bg-white/5 p-4 border-s-4 ${alertSeverityBorderClass(rule.severity)}`}
                >
                  <button
                    type="button"
                    disabled={!canEdit}
                    onClick={() => handleToggle(rule.id)}
                    className={`h-6 w-12 rounded-full transition ${rule.enabled ? 'bg-sky-500' : 'bg-white/10'} ${!canEdit ? 'cursor-not-allowed opacity-60' : ''}`}
                  >
                    <div
                      className={`h-5 w-5 rounded-full bg-white transition ${rule.enabled ? 'translate-x-6' : 'translate-x-1'}`}
                    />
                  </button>
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <h4 className="text-sm font-semibold">{rule.name}</h4>
                      <StatusBadge status={severityToBadgeStatus(rule.severity)} label={rule.severity} />
                      <span
                        className={`rounded-full px-2 py-0.5 text-[10px] font-medium uppercase ${
                          rule.enabled
                            ? 'bg-emerald-500/20 text-emerald-200'
                            : 'bg-white/10 text-white/50'
                        }`}
                      >
                        {rule.enabled ? t('alerts.ruleEnabled') : t('alerts.ruleDisabled')}
                      </span>
                    </div>
                    <p className="mt-1 text-xs text-white/70">
                      {t('alerts.ruleConditionSeparator')} {ruleConditionText(rule, t)}
                    </p>
                    <div className="mt-1 flex flex-wrap gap-2 text-xs text-white/40">
                      <span>{t('alerts.lastTriggered')}: {rule.lastTriggered}</span>
                      <span>{t('alerts.triggers7d')}: {rule.triggerCount7d}</span>
                    </div>
                  </div>
                  {canEdit && (
                    <button
                      type="button"
                      onClick={() => handleDelete(rule.id)}
                      className="text-xs text-red-400 hover:text-red-300"
                    >
                      {t('alerts.delete')}
                    </button>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {activeTab === 'history' && (
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <AlertHistoryFilterBar
            filters={historyFilters}
            rules={safeRules}
            onChange={setHistoryFilters}
            onApply={(next) => {
              const applied = next ?? historyFilters
              if (next) setHistoryFilters(next)
              setAppliedHistoryFilters(applied)
              setRefreshKey((k) => k + 1)
            }}
            onClear={() => {
              setHistoryFilters({})
              setAppliedHistoryFilters({})
              setRefreshKey((k) => k + 1)
            }}
          />

          {tabLoading ? (
            <p className="text-sm text-white/60">{t('agents.loading')}</p>
          ) : history.length === 0 ? (
            <div className="py-10 text-center text-sm text-white/60">{t('alerts.historyEmpty')}</div>
          ) : (
            <div className="space-y-3">
              {history.map((e) => {
                const isResolved = e.status === 'resolved'
                const canAct = canAcknowledge && !isResolved && e.status !== 'acknowledged'
                const conditionText = historyConditionText(e, safeRules, t)

                return (
                  <div key={e.id} className={`rounded-lg border border-white/5 bg-white/5 p-4 border-s-4 ${alertSeverityBorderClass(e.severity, e.status)}`}>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <h4 className="text-sm font-semibold">{e.title}</h4>
                      <div className="flex flex-wrap items-center gap-2">
                        <StatusBadge
                          status={alertStatusToBadgeStatus(e.status)}
                          label={alertStatusLabel(e.status, t)}
                        />
                        <StatusBadge status={severityToBadgeStatus(e.severity)} label={e.severity} />
                      </div>
                    </div>
                    {conditionText ? (
                      <p className="mt-1 text-xs text-white/50">{conditionText}</p>
                    ) : null}
                    <p className="mt-1 text-xs text-white/60">{e.message}</p>
                    <div className="mt-2 flex flex-wrap items-center gap-3 text-xs text-white/40">
                      <span>{new Date(e.triggered_at).toLocaleString()}</span>
                      {(e.occurrence_count ?? 1) > 1 && (
                        <span>{t('alerts.occurrenceCount')}: {e.occurrence_count}</span>
                      )}
                      {e.last_seen_at && (
                        <span>{t('alerts.lastSeen')}: {new Date(e.last_seen_at).toLocaleString()}</span>
                      )}
                    </div>
                    <div className="mt-3 flex flex-wrap gap-2">
                      <button
                        type="button"
                        onClick={() => setDetailEvent(e)}
                        className="text-xs text-sky-400 hover:text-sky-300"
                      >
                        {t('alerts.viewDetails')}
                      </button>
                      {canAct && (
                        <button
                          type="button"
                          onClick={() => handleAcknowledge(e.id)}
                          className="text-xs text-amber-400 hover:text-amber-300"
                        >
                          {t('alerts.acknowledge')}
                        </button>
                      )}
                    </div>
                  </div>
                )
              })}
            </div>
          )}
        </div>
      )}

      {createOpen && selectedWorkspaceId && (
        <CreateAlertRuleModal
          workspaceId={Number(selectedWorkspaceId)}
          onClose={() => setCreateOpen(false)}
          onCreated={() => {
            setCreateOpen(false)
            setRefreshKey((k) => k + 1)
          }}
        />
      )}

      {detailEvent && (
        <AlertDetailModal
          event={detailEvent}
          rules={safeRules}
          canAcknowledge={canAcknowledge && detailEvent.status !== 'resolved' && detailEvent.status !== 'acknowledged'}
          onAcknowledge={() => handleAcknowledge(detailEvent.id)}
          onClose={() => setDetailEvent(null)}
          onExplainAlert={
            aiAvailable
              ? () => {
                  const condition = historyConditionText(detailEvent, safeRules, t)
                  setAiSeed({
                    id: Date.now(),
                    agent: 'anomaly_detector',
                    question: `Explain this alert: ${detailEvent.title}. ${detailEvent.message ?? ''}`,
                    autoSend: true,
                    quick: true,
                    context: {
                      source: 'qynsight_alerts',
                      host: detailEvent.host_name ?? undefined,
                      metrics: {
                        alert: detailEvent.title,
                        severity: detailEvent.severity,
                        condition,
                      },
                    },
                  })
                  setAiOpen(true)
                }
              : undefined
          }
        />
      )}

      {selectedWorkspaceId ? (
        <AIAgentDrawer
          open={aiOpen}
          workspaceId={Number(selectedWorkspaceId)}
          seed={aiSeed}
          onClose={() => {
            setAiOpen(false)
            setAiSeed(null)
          }}
        />
      ) : null}
    </div>
  )
}

function AlertDetailModal({
  event,
  rules,
  canAcknowledge,
  onAcknowledge,
  onClose,
  onExplainAlert,
}: {
  event: AlertHistoryEvent
  rules: AlertRule[]
  canAcknowledge: boolean
  onAcknowledge: () => void
  onClose: () => void
  onExplainAlert?: () => void
}) {
  const { t } = useLanguage()
  const isResolved = event.status === 'resolved'
  const conditionText = historyConditionText(event, rules, t)

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
      <div className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border border-white/10 bg-[#0f151d] p-6 text-white">
        <h2 className="text-lg font-semibold">{t('alerts.details')}</h2>
        <div className="mt-4 space-y-2 text-sm">
          <p><span className="text-white/50">{t('alerts.rule')}:</span> {event.rule_name ?? event.rule_id ?? '—'}</p>
          {conditionText ? (
            <p><span className="text-white/50">{t('alerts.field.metric')}:</span> {conditionText}</p>
          ) : null}
          <p><span className="text-white/50">{t('alerts.host')}:</span> {event.host_name ?? '—'}</p>
          <p><span className="text-white/50">{t('alerts.service')}:</span> {event.service_name ?? '—'}</p>
          <p>
            <span className="text-white/50">{t('alerts.filter.status')}:</span>{' '}
            {alertStatusLabel(event.status, t)}
          </p>
          <p><span className="text-white/50">{t('alerts.occurrenceCount')}:</span> {event.occurrence_count ?? 1}</p>
          {event.opened_at && (
            <p><span className="text-white/50">{t('alerts.openedAt')}:</span> {new Date(event.opened_at).toLocaleString()}</p>
          )}
          {event.last_seen_at && (
            <p><span className="text-white/50">{t('alerts.lastSeen')}:</span> {new Date(event.last_seen_at).toLocaleString()}</p>
          )}
          {event.acknowledged_at && (
            <p><span className="text-white/50">{t('alerts.acknowledgedAt')}:</span> {new Date(event.acknowledged_at).toLocaleString()}</p>
          )}
          {event.resolved_at && (
            <p><span className="text-white/50">{t('alerts.resolvedAt')}:</span> {new Date(event.resolved_at).toLocaleString()}</p>
          )}
          {event.message && <p className="text-white/70">{event.message}</p>}
        </div>
        <div className="mt-6 flex flex-wrap justify-end gap-2">
          {onExplainAlert ? (
            <button
              type="button"
              onClick={onExplainAlert}
              className="rounded-lg border border-orange-500/40 bg-orange-500/15 px-4 py-2 text-sm text-orange-100 hover:bg-orange-500/25"
            >
              {t('ai.action.explainAlert')}
            </button>
          ) : null}
          {canAcknowledge && !isResolved && (
            <button
              type="button"
              onClick={onAcknowledge}
              className="rounded-lg bg-amber-600 px-4 py-2 text-sm text-white hover:bg-amber-500"
            >
              {t('alerts.acknowledge')}
            </button>
          )}
          <button type="button" onClick={onClose} className="rounded-lg border border-white/20 px-4 py-2 text-sm text-white/70">
            {t('agents.cancel')}
          </button>
        </div>
      </div>
    </div>
  )
}

function CreateAlertRuleModal({
  workspaceId,
  onClose,
  onCreated,
}: {
  workspaceId: number
  onClose: () => void
  onCreated: () => void
}) {
  const { t } = useLanguage()
  const [saving, setSaving] = useState(false)
  const [hostsLoading, setHostsLoading] = useState(true)
  const [hosts, setHosts] = useState<ObserveTargetHostOption[]>([])
  const [form, setForm] = useState<CreateAlertRulePayload>({
    name: '',
    severity: 'warning',
    target_scope: 'all',
    metric_condition: 'cpu',
    operator: '>',
    threshold_value: 80,
    duration_seconds: 300,
    enabled: true,
  })

  useEffect(() => {
    let cancelled = false
    setHostsLoading(true)
    observeService
      .getTargetHosts(workspaceId)
      .then((list) => {
        if (!cancelled) setHosts(Array.isArray(list) ? list.filter((h) => h.enabled !== false) : [])
      })
      .catch(() => {
        if (!cancelled) setHosts([])
      })
      .finally(() => {
        if (!cancelled) setHostsLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [workspaceId])

  const allServiceOptions = useMemo(() => {
    const items: Array<{
      hostId: number
      hostName: string
      serviceKey: string
      serviceName: string
      optionValue: string
    }> = []

    for (const host of hosts) {
      for (const svc of (host.services ?? []).filter((s) => s.enabled !== false)) {
        const serviceKey = svc.service_key || svc.name
        items.push({
          hostId: host.id,
          hostName: host.name,
          serviceKey,
          serviceName: svc.name,
          optionValue: `${host.id}:${serviceKey}`,
        })
      }
    }

    return items.sort((a, b) =>
      `${a.hostName} ${a.serviceName}`.localeCompare(`${b.hostName} ${b.serviceName}`)
    )
  }, [hosts])

  const selectedServiceValue =
    form.target_host_id != null && form.target_service_key
      ? `${form.target_host_id}:${form.target_service_key}`
      : ''

  const scopeNeedsHost = form.target_scope === 'selected_target'
  const scopeNeedsService = form.target_scope === 'selected_service'

  const formValid =
    form.name.trim() !== '' &&
    (!scopeNeedsHost || form.target_host_id != null) &&
    (!scopeNeedsService || (form.target_host_id != null && Boolean(form.target_service_key)))

  const submit = async () => {
    if (!formValid) return
    try {
      setSaving(true)
      const payload: CreateAlertRulePayload = {
        ...form,
        target_host_id:
          form.target_scope === 'all' ? null : form.target_host_id ?? null,
        target_service_key:
          form.target_scope === 'selected_service' ? form.target_service_key ?? null : null,
      }
      await observeService.createAlertRule(workspaceId, payload)
      onCreated()
    } finally {
      setSaving(false)
    }
  }

  const handleScopeChange = (target_scope: CreateAlertRulePayload['target_scope']) => {
    setForm({
      ...form,
      target_scope,
      target_host_id: null,
      target_service_key: null,
    })
  }

  const handleHostChange = (hostId: number | null) => {
    setForm({
      ...form,
      target_host_id: hostId,
      target_service_key: null,
    })
  }

  const handleServiceSelection = (optionValue: string) => {
    if (!optionValue) {
      setForm({
        ...form,
        target_host_id: null,
        target_service_key: null,
      })
      return
    }

    const separator = optionValue.indexOf(':')
    if (separator === -1) return

    const hostId = Number(optionValue.slice(0, separator))
    const serviceKey = optionValue.slice(separator + 1)
    if (!hostId || !serviceKey) return

    setForm({
      ...form,
      target_host_id: hostId,
      target_service_key: serviceKey,
    })
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
      <div className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border border-white/10 bg-[#0f151d] p-6 text-white">
        <h2 className="text-lg font-semibold">{t('alerts.createRule')}</h2>
        <div className="mt-4 space-y-4">
          <FormField label={t('alerts.field.name')} hint={t('alerts.field.nameHint')} required>
            <input
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
              className={inputClass}
            />
          </FormField>

          <FormField label={t('alerts.field.severity')} hint={t('alerts.field.severityHint')} required>
            <select
              value={form.severity}
              onChange={(e) => setForm({ ...form, severity: e.target.value as 'critical' | 'warning' })}
              className={inputClass}
            >
              <option value="warning">{t('alerts.warning')}</option>
              <option value="critical">{t('alerts.critical')}</option>
            </select>
          </FormField>

          <FormField label={t('alerts.field.scope')} hint={t('alerts.field.scopeHint')} required>
            <select
              value={form.target_scope}
              onChange={(e) => handleScopeChange(e.target.value as CreateAlertRulePayload['target_scope'])}
              className={inputClass}
            >
              <option value="all">{t('alerts.scope.all')}</option>
              <option value="selected_target">{t('alerts.scope.target')}</option>
              <option value="selected_service">{t('alerts.scope.service')}</option>
            </select>
          </FormField>

          {scopeNeedsHost && (
            <FormField
              label={t('alerts.field.selectHost')}
              hint={t('alerts.field.selectHostHint')}
              required
            >
              {hostsLoading ? (
                <p className="text-xs text-white/50">{t('agents.loading')}</p>
              ) : hosts.length === 0 ? (
                <p className="text-xs text-amber-400/90">{t('alerts.noTargetsConfigured')}</p>
              ) : (
                <select
                  value={form.target_host_id ?? ''}
                  onChange={(e) =>
                    handleHostChange(e.target.value ? Number(e.target.value) : null)
                  }
                  className={inputClass}
                >
                  <option value="">{t('alerts.field.selectHostPlaceholder')}</option>
                  {hosts.map((host) => (
                    <option key={host.id} value={host.id}>
                      {host.name} ({host.address})
                    </option>
                  ))}
                </select>
              )}
              {!hostsLoading && !form.target_host_id && (
                <p className="text-xs text-amber-400/90">{t('alerts.validation.selectHost')}</p>
              )}
            </FormField>
          )}

          {scopeNeedsService && (
            <FormField
              label={t('alerts.field.selectService')}
              hint={t('alerts.field.selectServiceHint')}
              required
            >
              {hostsLoading ? (
                <p className="text-xs text-white/50">{t('agents.loading')}</p>
              ) : allServiceOptions.length === 0 ? (
                <p className="text-xs text-amber-400/90">
                  {hosts.length === 0 ? t('alerts.noTargetsConfigured') : t('alerts.noServicesConfigured')}
                </p>
              ) : (
                <select
                  value={selectedServiceValue}
                  onChange={(e) => handleServiceSelection(e.target.value)}
                  className={inputClass}
                >
                  <option value="">{t('alerts.field.selectServicePlaceholder')}</option>
                  {allServiceOptions.map((item) => (
                    <option key={item.optionValue} value={item.optionValue}>
                      {item.hostName} — {item.serviceName}
                      {item.serviceKey !== item.serviceName ? ` (${item.serviceKey})` : ''}
                    </option>
                  ))}
                </select>
              )}
              {!hostsLoading && !selectedServiceValue && (
                <p className="text-xs text-amber-400/90">{t('alerts.validation.selectService')}</p>
              )}
            </FormField>
          )}

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <FormField label={t('alerts.field.metric')} hint={t('alerts.field.metricHint')} required>
              <select
                value={form.metric_condition}
                onChange={(e) => setForm({ ...form, metric_condition: e.target.value })}
                className={inputClass}
              >
                {ALERT_METRIC_OPTIONS.map((key) => (
                  <option key={key} value={key}>
                    {t(`alerts.metric.${key}`)}
                  </option>
                ))}
              </select>
            </FormField>
            <FormField label={t('alerts.field.operator')} hint={t('alerts.field.operatorHint')} required>
              <select
                value={form.operator}
                onChange={(e) => setForm({ ...form, operator: e.target.value })}
                className={inputClass}
              >
                {ALERT_OPERATORS.map((op) => (
                  <option key={op} value={op}>
                    {t(`alerts.operator.${op}`)}
                  </option>
                ))}
              </select>
            </FormField>
            <FormField label={t('alerts.field.threshold')} hint={t('alerts.field.thresholdHint')} required>
              <input
                type="number"
                value={form.threshold_value}
                onChange={(e) => setForm({ ...form, threshold_value: Number(e.target.value) })}
                className={inputClass}
              />
            </FormField>
          </div>

          <FormField label={t('alerts.field.duration')} hint={t('alerts.field.durationHint')} required>
            <input
              type="number"
              min={0}
              step={1}
              value={form.duration_seconds ?? 0}
              onChange={(e) => setForm({ ...form, duration_seconds: Number(e.target.value) })}
              className={inputClass}
            />
          </FormField>
        </div>
        <div className="mt-6 flex justify-end gap-2">
          <button type="button" onClick={onClose} className="rounded-lg border border-white/20 px-4 py-2 text-sm text-white/70">
            {t('agents.cancel')}
          </button>
          <button
            type="button"
            disabled={saving || !formValid}
            onClick={submit}
            className="rounded-lg bg-sky-600 px-4 py-2 text-sm text-white disabled:opacity-50"
          >
            {saving ? t('thresholds.saving') : t('alerts.saveRule')}
          </button>
        </div>
      </div>
    </div>
  )
}

function FormField({
  label,
  hint,
  required,
  children,
}: {
  label: string
  hint?: string
  required?: boolean
  children: ReactNode
}) {
  return (
    <div className="space-y-1">
      <span className="block text-xs font-medium text-white/70">
        {label}
        {required ? <span className="text-red-400"> *</span> : null}
      </span>
      {children}
      {hint ? <p className="text-xs leading-relaxed text-white/40">{hint}</p> : null}
    </div>
  )
}
