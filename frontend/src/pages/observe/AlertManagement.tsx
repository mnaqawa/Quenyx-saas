import { useState, useEffect, useCallback } from 'react'
import { useAlertRules, useAlertSummary } from '../../hooks/useObserveData'
import { StatCard } from '../../components/observe/StatCard'
import { PageHeader } from '../../components/observe/PageHeader'
import { Tabs } from '../../components/observe/Tabs'
import { StatusBadge } from '../../components/observe/StatusBadge'
import { useLanguage } from '../../i18n/LanguageContext'
import { useWorkspaceContext } from '../../workspaces/WorkspaceContext'
import { observeService } from '../../services/observeService'
import type { AlertHistoryEvent, CreateAlertRulePayload, NotificationChannel } from '../../types/observe'

export default function AlertManagement() {
  const { t } = useLanguage()
  const { selectedWorkspaceId, selectedWorkspaceRole } = useWorkspaceContext()
  const { rules, loading: rulesLoading } = useAlertRules(refreshKey)
  const { summary, loading: summaryLoading } = useAlertSummary(refreshKey)
  const [activeTab, setActiveTab] = useState('rules')
  const [history, setHistory] = useState<AlertHistoryEvent[]>([])
  const [channels, setChannels] = useState<NotificationChannel[]>([])
  const [tabLoading, setTabLoading] = useState(false)
  const [createOpen, setCreateOpen] = useState(false)
  const [refreshKey, setRefreshKey] = useState(0)

  const canEdit = selectedWorkspaceRole === 'owner' || selectedWorkspaceRole === 'admin'
  const safeRules = Array.isArray(rules) ? rules : []

  const loadTabData = useCallback(async () => {
    if (!selectedWorkspaceId) return
    setTabLoading(true)
    try {
      if (activeTab === 'history') {
        const events = await observeService.getAlertHistory(Number(selectedWorkspaceId))
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
  }, [activeTab, selectedWorkspaceId])

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
          title={t('alerts.card.rules')}
          value={String(summary?.alertRules?.total ?? 0)}
          detail={`${summary?.alertRules?.enabled ?? 0} ${t('alerts.enabled')}`}
        />
        <StatCard
          title={t('alerts.card.response')}
          value={summary?.avgResponseTime ?? '—'}
          detail={t('alerts.card.responseHint')}
        />
        <StatCard
          title={t('alerts.card.channels')}
          value={String(summary?.notificationChannels?.active ?? 0)}
          detail={`${t('alerts.of')} ${summary?.notificationChannels?.total ?? 0}`}
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
          {tabLoading ? (
            <p className="text-sm text-white/60">{t('agents.loading')}</p>
          ) : history.length === 0 ? (
            <div className="py-10 text-center text-sm text-white/60">{t('alerts.historyEmpty')}</div>
          ) : (
            <div className="space-y-3">
              {history.map((e) => (
                <div key={e.id} className="rounded-lg border border-white/5 bg-white/5 p-4">
                  <div className="flex items-center justify-between gap-2">
                    <h4 className="text-sm font-semibold">{e.title}</h4>
                    <StatusBadge status={e.severity} label={e.severity} />
                  </div>
                  <p className="mt-1 text-xs text-white/60">{e.message}</p>
                  <p className="mt-2 text-xs text-white/40">
                    {new Date(e.triggered_at).toLocaleString()} • {e.status}
                  </p>
                </div>
              ))}
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
                  <StatusBadge status={ch.configured ? 'ok' : 'unknown'} label={ch.configured ? t('alerts.configured') : t('alerts.notConfigured')} />
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
