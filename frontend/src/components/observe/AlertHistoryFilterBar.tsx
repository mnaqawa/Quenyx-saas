import { useMemo } from 'react'
import { DateTimeField } from './DateTimeField'
import { useLanguage } from '../../i18n/LanguageContext'
import type { AlertHistoryFilters, AlertRule } from '../../types/observe'

function pad2(n: number): string {
  return String(n).padStart(2, '0')
}

function toLocalDateTimeValue(date: Date): string {
  return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}T${pad2(date.getHours())}:${pad2(date.getMinutes())}`
}

const selectClass =
  'w-full rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-sm text-white focus:border-sky-500/50 focus:outline-none'

export function AlertHistoryFilterBar({
  filters,
  rules,
  onChange,
  onApply,
  onClear,
}: {
  filters: AlertHistoryFilters
  rules: AlertRule[]
  onChange: (next: AlertHistoryFilters) => void
  onApply: (next?: AlertHistoryFilters) => void
  onClear: () => void
}) {
  const { t } = useLanguage()

  const activeCount = useMemo(() => {
    let n = 0
    if (filters.status) n++
    if (filters.severity) n++
    if (filters.target) n++
    if (filters.rule) n++
    if (filters.date_from) n++
    if (filters.date_to) n++
    return n
  }, [filters])

  const applyPreset = (preset: 'today' | 'last24h' | 'last7d') => {
    const now = new Date()
    let from: Date
    const to: Date = now

    if (preset === 'today') {
      from = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 0, 0, 0, 0)
    } else if (preset === 'last24h') {
      from = new Date(now.getTime() - 24 * 60 * 60 * 1000)
    } else {
      from = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000)
    }

    const next = {
      ...filters,
      date_from: toLocalDateTimeValue(from),
      date_to: toLocalDateTimeValue(to),
    }
    onChange(next)
    onApply(next)
  }

  return (
    <div className="mb-5 rounded-xl border border-white/10 bg-white/[0.03] p-4">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 className="text-sm font-semibold text-white">{t('alerts.filter.title')}</h3>
          <p className="text-xs text-white/40">{t('alerts.filter.subtitle')}</p>
        </div>
        {activeCount > 0 && (
          <span className="rounded-full bg-sky-500/20 px-2.5 py-0.5 text-xs font-medium text-sky-300">
            {t('alerts.filter.activeCount').replace('{count}', String(activeCount))}
          </span>
        )}
      </div>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <label className="block">
          <span className="mb-1 block text-xs font-medium text-white/60">{t('alerts.filter.status')}</span>
          <select
            value={filters.status ?? ''}
            onChange={(e) => onChange({ ...filters, status: e.target.value || undefined })}
            className={selectClass}
          >
            <option value="">{t('alerts.filter.all')}</option>
            <option value="open">{t('alerts.status.open')}</option>
            <option value="acknowledged">{t('alerts.status.acknowledged')}</option>
            <option value="resolved">{t('alerts.status.resolved')}</option>
          </select>
        </label>

        <label className="block">
          <span className="mb-1 block text-xs font-medium text-white/60">{t('alerts.filter.severity')}</span>
          <select
            value={filters.severity ?? ''}
            onChange={(e) => onChange({ ...filters, severity: e.target.value || undefined })}
            className={selectClass}
          >
            <option value="">{t('alerts.filter.all')}</option>
            <option value="critical">{t('alerts.critical')}</option>
            <option value="warning">{t('alerts.warning')}</option>
          </select>
        </label>

        <label className="block">
          <span className="mb-1 block text-xs font-medium text-white/60">{t('alerts.filter.target')}</span>
          <input
            type="text"
            value={filters.target ?? ''}
            onChange={(e) => onChange({ ...filters, target: e.target.value || undefined })}
            placeholder={t('alerts.filter.targetPlaceholder')}
            className={selectClass}
          />
        </label>

        <label className="block">
          <span className="mb-1 block text-xs font-medium text-white/60">{t('alerts.filter.rule')}</span>
          <select
            value={filters.rule ?? ''}
            onChange={(e) => onChange({ ...filters, rule: e.target.value || undefined })}
            className={selectClass}
          >
            <option value="">{t('alerts.filter.all')}</option>
            {rules.map((r) => (
              <option key={r.id} value={r.id}>
                {r.name}
              </option>
            ))}
          </select>
        </label>
      </div>

      <div className="mt-4 border-t border-white/10 pt-4">
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <span className="text-xs font-medium text-white/60">{t('alerts.filter.dateRange')}</span>
          <div className="flex flex-wrap gap-2">
            <button
              type="button"
              onClick={() => applyPreset('today')}
              className="rounded-md border border-white/10 px-2.5 py-1 text-xs text-white/70 hover:bg-white/10"
            >
              {t('alerts.filter.presets.today')}
            </button>
            <button
              type="button"
              onClick={() => applyPreset('last24h')}
              className="rounded-md border border-white/10 px-2.5 py-1 text-xs text-white/70 hover:bg-white/10"
            >
              {t('alerts.filter.presets.last24h')}
            </button>
            <button
              type="button"
              onClick={() => applyPreset('last7d')}
              className="rounded-md border border-white/10 px-2.5 py-1 text-xs text-white/70 hover:bg-white/10"
            >
              {t('alerts.filter.presets.last7d')}
            </button>
          </div>
        </div>

        <div className="grid gap-4 md:grid-cols-2">
          <DateTimeField
            label={t('alerts.filter.dateFrom')}
            placeholder={t('alerts.datetime.placeholder')}
            value={filters.date_from}
            onChange={(v) => onChange({ ...filters, date_from: v })}
          />
          <DateTimeField
            label={t('alerts.filter.dateTo')}
            placeholder={t('alerts.datetime.placeholder')}
            value={filters.date_to}
            onChange={(v) => onChange({ ...filters, date_to: v })}
          />
        </div>
      </div>

      <div className="mt-4 flex flex-wrap justify-end gap-2 border-t border-white/10 pt-4">
        <button
          type="button"
          onClick={onClear}
          className="rounded-lg border border-white/20 px-4 py-2 text-sm text-white/70 hover:bg-white/5"
        >
          {t('alerts.clearFilters')}
        </button>
        <button
          type="button"
          onClick={() => onApply()}
          className="rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-500"
        >
          {t('alerts.applyFilters')}
        </button>
      </div>
    </div>
  )
}
