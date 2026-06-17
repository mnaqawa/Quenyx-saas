import { useCallback, useEffect, useState } from 'react'
import { useLanguage } from '../../i18n/LanguageContext'
import { observeService } from '../../services/observeService'
import type { MonitoringProfileCheck, MonitoringProfileCheckUpdate } from '../../types/observe'

interface MonitoringThresholdsPanelProps {
  workspaceId: string | number
  canEdit: boolean
  /** When true, renders without outer card chrome (for settings modal). */
  embedded?: boolean
}

export function MonitoringThresholdsPanel({ workspaceId, canEdit, embedded = false }: MonitoringThresholdsPanelProps) {
  const { t } = useLanguage()
  const [checks, setChecks] = useState<MonitoringProfileCheck[]>([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [expanded, setExpanded] = useState(true)

  const load = useCallback(async () => {
    try {
      setLoading(true)
      setError(null)
      const data = await observeService.getMonitoringProfile(Number(workspaceId))
      setChecks(data.checks ?? [])
    } catch (e) {
      setError(e instanceof Error ? e.message : t('thresholds.loadError'))
    } finally {
      setLoading(false)
    }
  }, [workspaceId, t])

  useEffect(() => {
    load()
  }, [load])

  const updateCheckArg = (serviceKey: string, key: string, value: number) => {
    setChecks((prev) =>
      prev.map((c) =>
        c.service_key === serviceKey
          ? { ...c, check_args: { ...c.check_args, [key]: value } }
          : c
      )
    )
  }

  const handleSave = async () => {
    if (!canEdit) return
    try {
      setSaving(true)
      setError(null)
      const payload: MonitoringProfileCheckUpdate[] = checks.map((c) => ({
        service_key: c.service_key,
        check_args: c.check_args,
        enabled: c.enabled,
      }))
      const data = await observeService.updateMonitoringProfile(Number(workspaceId), payload)
      setChecks(data.checks ?? [])
    } catch (e) {
      setError(e instanceof Error ? e.message : t('thresholds.saveError'))
    } finally {
      setSaving(false)
    }
  }

  const thresholdChecks = checks.filter((c) =>
    ['cpu', 'memory', 'disk', 'load', 'ping'].includes(c.service_key)
  )

  const content = (
    <div className={embedded ? 'space-y-4' : 'mt-4 space-y-4'}>
          {!canEdit && (
            <p className="text-xs text-amber-200/80">{t('thresholds.readOnly')}</p>
          )}
          {error && (
            <p className="text-xs text-red-300">{error}</p>
          )}
          {loading ? (
            <p className="text-xs text-white/50">{t('agents.loading')}</p>
          ) : (
            <div className="grid gap-4 md:grid-cols-2">
              {thresholdChecks.map((check) => (
                <div key={check.service_key} className="rounded-lg border border-white/10 bg-white/5 p-4">
                  <h4 className="text-xs font-semibold uppercase text-white/70">
                    {t(`thresholds.check.${check.service_key}`)}
                  </h4>
                  {check.service_key === 'ping' ? (
                    <p className="mt-2 text-xs text-white/50">{t('thresholds.pingHint')}</p>
                  ) : check.service_key === 'load' ? (
                    <p className="mt-2 text-xs text-white/50">{t('thresholds.loadHint')}</p>
                  ) : (
                    <div className="mt-3 flex flex-wrap gap-3">
                      <label className="text-xs text-white/70">
                        {t('thresholds.warn')}
                        <input
                          type="number"
                          disabled={!canEdit}
                          value={Number(check.check_args.warn_pct ?? '')}
                          onChange={(e) =>
                            updateCheckArg(check.service_key, 'warn_pct', Number(e.target.value))
                          }
                          className="ml-2 w-16 rounded border border-white/20 bg-black/20 px-2 py-1 text-white disabled:opacity-50"
                        />
                        %
                      </label>
                      <label className="text-xs text-white/70">
                        {t('thresholds.crit')}
                        <input
                          type="number"
                          disabled={!canEdit}
                          value={Number(check.check_args.crit_pct ?? '')}
                          onChange={(e) =>
                            updateCheckArg(check.service_key, 'crit_pct', Number(e.target.value))
                          }
                          className="ml-2 w-16 rounded border border-white/20 bg-black/20 px-2 py-1 text-white disabled:opacity-50"
                        />
                        %
                      </label>
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}
          {canEdit && !loading && (
            <div className="flex justify-end">
              <button
                type="button"
                onClick={handleSave}
                disabled={saving}
                className="rounded-lg bg-sky-600 px-4 py-2 text-xs font-medium text-white hover:bg-sky-500 disabled:opacity-50"
              >
                {saving ? t('thresholds.saving') : t('thresholds.save')}
              </button>
            </div>
          )}
        </div>
  )

  if (embedded) {
    return <div className="text-white">{content}</div>
  }

  return (
    <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
      <button
        type="button"
        onClick={() => setExpanded(!expanded)}
        className="flex w-full items-center justify-between text-left"
      >
        <div>
          <h3 className="text-sm font-semibold">{t('thresholds.title')}</h3>
          <p className="text-xs text-white/50">{t('thresholds.subtitle')}</p>
        </div>
        <span className="text-white/50">{expanded ? '▼' : '▶'}</span>
      </button>

      {expanded && content}
    </div>
  )
}
