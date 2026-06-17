import { useState, useEffect, useCallback, type ComponentProps } from 'react'
import { useAlertRules, useAlertSummary } from '../../hooks/useObserveData'
import { StatCard } from '../../components/observe/StatCard'
import { PageHeader } from '../../components/observe/PageHeader'
import { Tabs } from '../../components/observe/Tabs'
import { StatusBadge } from '../../components/observe/StatusBadge'
import { useLanguage } from '../../i18n/LanguageContext'
import { useWorkspaceContext } from '../../workspaces/WorkspaceContext'
import { observeService } from '../../services/observeService'
import type {
  AlertHistoryEvent,
  AlertHistoryFilters,
  CreateAlertRulePayload,
  NotificationChannel,
} from '../../types/observe'

type BadgeStatus = ComponentProps<typeof StatusBadge>['status']

function severityToBadgeStatus(severity: string): BadgeStatus {
  if (severity === 'critical') return 'critical'
  if (severity === 'warning') return 'warning'
  return 'degraded'
}

function alertStatusToBadgeStatus(status: string): BadgeStatus {
  if (status === 'open' || status === 'active') return 'critical'
  if (status === 'acknowledged') return 'warning'
  if (status === 'resolved') return 'completed'
  return 'degraded'
}

function alertStatusLabel(status: string, t: (key: string) => string): string {
  if (status === 'open' || status === 'active') return t('alerts.status.open')
  if (status === 'acknowledged') return t('alerts.status.acknowledged')
  if (status === 'resolved') return t('alerts.status.resolved')
  return status
}

export default function AlertManagement() {
  const { t } = useLanguage()
  const { selectedWorkspaceId, selectedWorkspaceRole } = useWorkspaceContext()
  const [activeTab, setActiveTab] = useState('rules')
  const [history, setHistory] = useState<AlertHistoryEvent[]>([])
  const [channels, setChannels] = useState<NotificationChannel[]>([])
  const [tabLoading, setTabLoading] = useState(false)
  const [createOpen, setCreateOpen] = useState(false)
  const [refreshKey, setRefreshKey] = useState(0)
  const [detailEvent, setDetailEvent] = useState<AlertHistoryEvent | null>(null)
  const [historyFilters, setHistoryFilters] = useState<AlertHistoryFilters>({})
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
        const events = await observeService.getAlertHistory(Number(selectedWorkspaceId), historyFilters)
        setHistory(Array.isArray(events) ? events : [])
      }
      if (activeTab === 'channels') {
        const ch = await observeService.getNotificationChannels(Number(selectedWorkspaceId))
        setChannels(Array.isArray(ch) ? ch : [])
      }
    } catch {
      if (activeTab === 'history') setHistory([])
      if (activeTab === 'channels') setChannels([])
    } finally {
      setTabLoading(false)
    }
  }, [activeTab, selectedWorkspaceId, historyFilters])

  useEffect(() => {
    if (activeTab === 'history' || activeTab === 'channels') {
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
    { id: 'channels', label: t('alerts.tab.channels') },
    { id: 'escalation', label: t('alerts.tab.escalation') },
  ]

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('alerts.title')}
        subtitle={t('alerts.subtitle')}
        actions={
          canEdit ? (
            <button
              type="button"
              onClick={() => setCreateOpen(true)}
              className="rounded-lg bg-sky-600 px-4 py-1.5 text-xs font-semibold text-white hover:bg-sky-500"
            >
              {t('alerts.createRule')}
            </button>
          ) : null
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
                  className="flex flex-wrap items-center gap-4 rounded-lg border border-white/5 bg-white/5 p-4"
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
                      <span className="text-xs text-white/60">{rule.condition}</span>
                    </div>
                    <div className="mt-1 flex flex-wrap gap-2 text-xs text-white/40">
                      <span>{t('alerts.lastTriggered')}: {rule.lastTriggered}</span>
                      <span>{t('alerts.triggers7d')}: {rule.triggerCount7d}</span>
                    </div>
                  </div>
                  <StatusBadge status={rule.severity} label={rule.severity} />
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
          <div className="mb-4 flex flex-wrap items-end gap-3">
            <label className="text-xs text-white/60">
              {t('alerts.filter.status')}
              <select
                value={historyFilters.status ?? ''}
                onChange={(e) => setHistoryFilters((f) => ({ ...f, status: e.target.value || undefined }))}
                className="mt-1 block rounded border border-white/20 bg-white/5 px-2 py-1.5 text-sm text-white"
              >
                <option value="">{t('alerts.filter.all')}</option>
                <option value="open">{t('alerts.status.open')}</option>
                <option value="acknowledged">{t('alerts.status.acknowledged')}</option>
                <option value="resolved">{t('alerts.status.resolved')}</option>
              </select>
            </label>
            <label className="text-xs text-white/60">
              {t('alerts.filter.severity')}
              <select
                value={historyFilters.severity ?? ''}
                onChange={(e) => setHistoryFilters((f) => ({ ...f, severity: e.target.value || undefined }))}
                className="mt-1 block rounded border border-white/20 bg-white/5 px-2 py-1.5 text-sm text-white"
              >
                <option value="">{t('alerts.filter.all')}</option>
                <option value="critical">{t('alerts.critical')}</option>
                <option value="warning">{t('alerts.warning')}</option>
              </select>
            </label>
            <label className="text-xs text-white/60">
              {t('alerts.filter.target')}
              <input
                type="text"
                value={historyFilters.target ?? ''}
                onChange={(e) => setHistoryFilters((f) => ({ ...f, target: e.target.value || undefined }))}
                className="mt-1 block w-32 rounded border border-white/20 bg-white/5 px-2 py-1.5 text-sm text-white"
              />
            </label>
            <label className="text-xs text-white/60">
              {t('alerts.filter.rule')}
              <select
                value={historyFilters.rule ?? ''}
                onChange={(e) => setHistoryFilters((f) => ({ ...f, rule: e.target.value || undefined }))}
                className="mt-1 block rounded border border-white/20 bg-white/5 px-2 py-1.5 text-sm text-white"
              >
                <option value="">{t('alerts.filter.all')}</option>
                {safeRules.map((r) => (
                  <option key={r.id} value={r.id}>{r.name}</option>
                ))}
              </select>
            </label>
            <label className="text-xs text-white/60">
              {t('alerts.filter.dateFrom')}
              <input
                type="date"
                value={historyFilters.date_from ?? ''}
                onChange={(e) => setHistoryFilters((f) => ({ ...f, date_from: e.target.value || undefined }))}
                className="mt-1 block rounded border border-white/20 bg-white/5 px-2 py-1.5 text-sm text-white"
              />
            </label>
            <label className="text-xs text-white/60">
              {t('alerts.filter.dateTo')}
              <input
                type="date"
                value={historyFilters.date_to ?? ''}
                onChange={(e) => setHistoryFilters((f) => ({ ...f, date_to: e.target.value || undefined }))}
                className="mt-1 block rounded border border-white/20 bg-white/5 px-2 py-1.5 text-sm text-white"
              />
            </label>
            <button
              type="button"
              onClick={() => setRefreshKey((k) => k + 1)}
              className="rounded-lg bg-sky-600 px-3 py-1.5 text-xs text-white hover:bg-sky-500"
            >
              {t('alerts.applyFilters')}
            </button>
            <button
              type="button"
              onClick={() => {
                setHistoryFilters({})
                setRefreshKey((k) => k + 1)
              }}
              className="rounded-lg border border-white/20 px-3 py-1.5 text-xs text-white/70"
            >
              {t('alerts.clearFilters')}
            </button>
          </div>

          {tabLoading ? (
            <p className="text-sm text-white/60">{t('agents.loading')}</p>
          ) : history.length === 0 ? (
            <div className="py-10 text-center text-sm text-white/60">{t('alerts.historyEmpty')}</div>
          ) : (
            <div className="space-y-3">
              {history.map((e) => {
                const isResolved = e.status === 'resolved'
                const canAct = canAcknowledge && !isResolved && e.status !== 'acknowledged'

                return (
                  <div key={e.id} className="rounded-lg border border-white/5 bg-white/5 p-4">
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

      {activeTab === 'channels' && (
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          {tabLoading ? (
            <p className="text-sm text-white/60">{t('agents.loading')}</p>
          ) : channels.length === 0 ? (
            <div className="py-10 text-center">
              <p className="text-sm text-white/60">{t('alerts.channelsEmpty')}</p>
              <p className="mt-2 text-xs text-white/40">{t('alerts.channelsEmptyHint')}</p>
            </div>
          ) : (
            <div className="space-y-3">
              {channels.map((ch) => (
                <div key={ch.id} className="flex items-center justify-between rounded-lg border border-white/5 bg-white/5 p-4">
                  <div>
                    <h4 className="text-sm font-semibold">{ch.name}</h4>
                    <p className="text-xs text-white/50">{ch.type}</p>
                  </div>
                  <StatusBadge status={ch.configured ? 'connected' : 'stopped'} label={ch.configured ? t('alerts.configured') : t('alerts.notConfigured')} />
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {activeTab === 'escalation' && (
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-10 text-center text-white">
          <p className="text-sm text-white/60">{t('alerts.escalationEmpty')}</p>
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
          canAcknowledge={canAcknowledge && detailEvent.status !== 'resolved' && detailEvent.status !== 'acknowledged'}
          onAcknowledge={() => handleAcknowledge(detailEvent.id)}
          onClose={() => setDetailEvent(null)}
        />
      )}
    </div>
  )
}

function AlertDetailModal({
  event,
  canAcknowledge,
  onAcknowledge,
  onClose,
}: {
  event: AlertHistoryEvent
  canAcknowledge: boolean
  onAcknowledge: () => void
  onClose: () => void
}) {
  const { t } = useLanguage()
  const isResolved = event.status === 'resolved'

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
      <div className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border border-white/10 bg-[#0f151d] p-6 text-white">
        <h2 className="text-lg font-semibold">{t('alerts.details')}</h2>
        <div className="mt-4 space-y-2 text-sm">
          <p><span className="text-white/50">{t('alerts.rule')}:</span> {event.rule_name ?? event.rule_id ?? '—'}</p>
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
        <div className="mt-6 flex justify-end gap-2">
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

  const submit = async () => {
    try {
      setSaving(true)
      await observeService.createAlertRule(workspaceId, form)
      onCreated()
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
      <div className="w-full max-w-lg rounded-xl border border-white/10 bg-[#0f151d] p-6 text-white">
        <h2 className="text-lg font-semibold">{t('alerts.createRule')}</h2>
        <div className="mt-4 space-y-3">
          <input
            placeholder={t('alerts.field.name')}
            value={form.name}
            onChange={(e) => setForm({ ...form, name: e.target.value })}
            className="w-full rounded border border-white/20 bg-white/5 px-3 py-2 text-sm"
          />
          <select
            value={form.severity}
            onChange={(e) => setForm({ ...form, severity: e.target.value as 'critical' | 'warning' })}
            className="w-full rounded border border-white/20 bg-white/5 px-3 py-2 text-sm"
          >
            <option value="warning">{t('alerts.warning')}</option>
            <option value="critical">{t('alerts.critical')}</option>
          </select>
          <select
            value={form.target_scope}
            onChange={(e) => setForm({ ...form, target_scope: e.target.value as CreateAlertRulePayload['target_scope'] })}
            className="w-full rounded border border-white/20 bg-white/5 px-3 py-2 text-sm"
          >
            <option value="all">{t('alerts.scope.all')}</option>
            <option value="selected_target">{t('alerts.scope.target')}</option>
            <option value="selected_service">{t('alerts.scope.service')}</option>
          </select>
          <div className="grid grid-cols-3 gap-2">
            <input
              placeholder={t('alerts.field.metric')}
              value={form.metric_condition}
              onChange={(e) => setForm({ ...form, metric_condition: e.target.value })}
              className="rounded border border-white/20 bg-white/5 px-3 py-2 text-sm"
            />
            <select
              value={form.operator}
              onChange={(e) => setForm({ ...form, operator: e.target.value })}
              className="rounded border border-white/20 bg-white/5 px-3 py-2 text-sm"
            >
              <option>&gt;</option>
              <option>&gt;=</option>
              <option>&lt;</option>
              <option>&lt;=</option>
              <option>=</option>
              <option>!=</option>
            </select>
            <input
              type="number"
              placeholder={t('alerts.field.threshold')}
              value={form.threshold_value}
              onChange={(e) => setForm({ ...form, threshold_value: Number(e.target.value) })}
              className="rounded border border-white/20 bg-white/5 px-3 py-2 text-sm"
            />
          </div>
          <input
            type="number"
            placeholder={t('alerts.field.duration')}
            value={form.duration_seconds ?? 300}
            onChange={(e) => setForm({ ...form, duration_seconds: Number(e.target.value) })}
            className="w-full rounded border border-white/20 bg-white/5 px-3 py-2 text-sm"
          />
        </div>
        <div className="mt-6 flex justify-end gap-2">
          <button type="button" onClick={onClose} className="rounded-lg border border-white/20 px-4 py-2 text-sm text-white/70">
            {t('agents.cancel')}
          </button>
          <button
            type="button"
            disabled={saving || !form.name.trim()}
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
