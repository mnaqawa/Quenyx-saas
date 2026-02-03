import { useState } from 'react'
import { useAlertRules, useAlertSummary } from '../../hooks/useObserveData'
import { StatCard } from '../../components/observe/StatCard'
import { PageHeader } from '../../components/observe/PageHeader'
import { Tabs } from '../../components/observe/Tabs'
import { StatusBadge } from '../../components/observe/StatusBadge'

export default function AlertManagement() {
  const { rules, loading: rulesLoading } = useAlertRules()
  const { summary, loading: summaryLoading } = useAlertSummary()
  const [activeTab, setActiveTab] = useState('rules')

  if (rulesLoading || summaryLoading) {
    return <div className="text-sm text-white/60">Loading...</div>
  }

  if (!summary) {
    return <div className="text-sm text-white/60">No data available</div>
  }

  const tabs = [
    { id: 'rules', label: 'Alert Rules' },
    { id: 'history', label: 'Alert History' },
    { id: 'channels', label: 'Notification Channels' },
    { id: 'escalation', label: 'Escalation Policies' },
  ]

  return (
    <div className="space-y-6">
      <PageHeader
        title="Alert Management"
        subtitle="Configure alerts, manage notification channels, and track alert history"
        actions={
          <>
            <button title="Coming soon" disabled className="cursor-not-allowed rounded-lg border border-sky-500/20 bg-sky-500/5 px-4 py-1.5 text-xs text-sky-200/60">
              Global Settings
            </button>
            <button title="Coming soon" disabled className="cursor-not-allowed rounded-lg bg-sky-500/50 px-4 py-1.5 text-xs font-semibold text-white/70">
              + Create Alert Rule
            </button>
          </>
        }
      />

      <div className="grid gap-4 md:grid-cols-4">
        <StatCard
          title="Active Alerts"
          value={summary.activeAlerts.total.toString()}
          detail={`${summary.activeAlerts.critical} critical, ${summary.activeAlerts.warning} warning`}
        />
        <StatCard
          title="Alert Rules"
          value={summary.alertRules.total.toString()}
          detail={`${summary.alertRules.enabled} enabled`}
        />
        <StatCard
          title="Avg Response Time"
          value={summary.avgResponseTime}
          detail="Last 24 hours"
        />
        <StatCard
          title="Notification Channels"
          value={summary.notificationChannels.active.toString()}
          detail={`of ${summary.notificationChannels.total} configured`}
        />
      </div>

      <Tabs tabs={tabs} activeTab={activeTab} onTabChange={setActiveTab} />

      {activeTab === 'rules' && (
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <div className="mb-4">
            <h3 className="text-sm font-semibold">Alert Rules Configuration</h3>
            <p className="text-xs text-white/60">Manage monitoring rules and alert conditions</p>
          </div>
          <div className="space-y-3">
            {rules.map((rule) => (
              <div
                key={rule.id}
                className="flex items-center gap-4 rounded-lg border border-white/5 bg-white/5 p-4"
              >
                <div className="flex items-center gap-3">
                  <button
                    title="Coming soon"
                    disabled
                    className={`cursor-not-allowed h-6 w-12 rounded-full transition opacity-60 ${
                      rule.enabled ? 'bg-sky-500' : 'bg-white/10'
                    }`}
                  >
                    <div
                      className={`h-5 w-5 rounded-full bg-white transition ${
                        rule.enabled ? 'translate-x-6' : 'translate-x-1'
                      }`}
                    />
                  </button>
                  <div className="flex-1">
                    <div className="flex items-center gap-2">
                      <h4 className="text-sm font-semibold">{rule.name}</h4>
                      <span className="text-xs text-white/60">{rule.condition}</span>
                    </div>
                    <div className="mt-2 flex items-center gap-2">
                      <div className="flex gap-1">
                        {rule.notificationChannels.map((channel) => (
                          <span
                            key={channel}
                            className="rounded-full bg-white/10 px-2 py-0.5 text-[10px] text-white/70"
                          >
                            {channel}
                          </span>
                        ))}
                      </div>
                      <span className="text-xs text-white/40">Last triggered: {rule.lastTriggered}</span>
                      <span className="text-xs text-white/40">Triggers (7d): {rule.triggerCount7d}</span>
                    </div>
                  </div>
                  <div className="flex items-center gap-2">
                    <StatusBadge
                      status={rule.severity}
                      label={`▲ ${rule.severity.charAt(0).toUpperCase() + rule.severity.slice(1)}`}
                    />
                    <button title="Coming soon" disabled className="cursor-not-allowed rounded-lg border border-white/10 bg-white/5 p-1.5 text-white/40">
                      ✏️
                    </button>
                    <button title="Coming soon" disabled className="cursor-not-allowed rounded-lg border border-white/10 bg-white/5 p-1.5 text-white/40">
                      🗑️
                    </button>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {activeTab !== 'rules' && (
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-10 text-center text-white">
          <p className="text-sm text-white/60">{tabs.find((t) => t.id === activeTab)?.label} view coming soon</p>
        </div>
      )}
    </div>
  )
}
