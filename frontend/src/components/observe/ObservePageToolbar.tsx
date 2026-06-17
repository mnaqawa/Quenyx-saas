import { useLanguage } from '../../i18n/LanguageContext'
import type { ObserveAutoRefreshInterval } from '../../hooks/useObserveAutoRefresh'

interface ObservePageToolbarProps {
  interval: ObserveAutoRefreshInterval
  onIntervalChange: (value: ObserveAutoRefreshInterval) => void
  secondsAgo: number | null
  onRefresh: () => void
  refreshing?: boolean
  settingsLabel?: string
  onSettings?: () => void
  disabled?: boolean
}

export function ObservePageToolbar({
  interval,
  onIntervalChange,
  secondsAgo,
  onRefresh,
  refreshing = false,
  settingsLabel,
  onSettings,
  disabled = false,
}: ObservePageToolbarProps) {
  const { t } = useLanguage()

  return (
    <div className="flex flex-wrap items-center gap-2">
      {secondsAgo !== null ? (
        <span className="text-xs text-white/50 tabular-nums">
          {t('observe.updatedAgo').replace('{seconds}', String(secondsAgo))}
        </span>
      ) : null}
      <select
        value={interval}
        onChange={(e) => onIntervalChange(e.target.value as ObserveAutoRefreshInterval)}
        disabled={disabled}
        className="rounded-lg border border-white/10 bg-[#0f151d] px-3 py-1.5 text-xs font-medium text-white outline-none transition hover:border-orange-500/40 disabled:opacity-50"
        aria-label={t('observe.autoRefresh.label')}
      >
        <option value="15" className="bg-[#0f151d]">{t('observe.autoRefresh.15s')}</option>
        <option value="30" className="bg-[#0f151d]">{t('observe.autoRefresh.30s')}</option>
        <option value="60" className="bg-[#0f151d]">{t('observe.autoRefresh.1m')}</option>
        <option value="300" className="bg-[#0f151d]">{t('observe.autoRefresh.5m')}</option>
        <option value="off" className="bg-[#0f151d]">{t('observe.autoRefresh.off')}</option>
      </select>
      <button
        type="button"
        onClick={onRefresh}
        disabled={disabled || refreshing}
        className="rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white transition hover:bg-white/10 disabled:cursor-not-allowed disabled:opacity-50"
      >
        {refreshing ? t('observe.refreshing') : t('observe.refresh')}
      </button>
      {onSettings ? (
        <button
          type="button"
          onClick={onSettings}
          disabled={disabled}
          className="rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white transition hover:bg-white/10 disabled:opacity-50"
        >
          {settingsLabel ?? t('common.settings')}
        </button>
      ) : null}
    </div>
  )
}
