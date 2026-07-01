import { useLanguage } from '../../i18n/LanguageContext'

interface GlobalSearchBarProps {
  onFocus: () => void
}

export function GlobalSearchBar({ onFocus }: GlobalSearchBarProps) {
  const { t } = useLanguage()

  return (
    <button
      type="button"
      onClick={onFocus}
      className="hidden min-w-[12rem] flex-1 items-center gap-2 rounded-xl border border-white/10 bg-white/[0.03] px-3 py-2 text-start text-xs text-white/45 transition hover:border-white/15 hover:bg-white/[0.06] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-500 md:flex lg:min-w-[16rem]"
      aria-label={t('globalSearch.label')}
    >
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="shrink-0">
        <circle cx="11" cy="11" r="8" />
        <path d="M21 21l-4.35-4.35" />
      </svg>
      <span className="flex-1 truncate">{t('globalSearch.placeholder')}</span>
      <kbd className="rounded border border-white/10 bg-white/5 px-1.5 py-0.5 text-[10px]">⌘K</kbd>
    </button>
  )
}
