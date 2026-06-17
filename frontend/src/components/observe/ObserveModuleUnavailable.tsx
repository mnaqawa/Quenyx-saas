import { useLanguage } from '../../i18n/LanguageContext'

export function ObserveModuleUnavailable() {
  const { t } = useLanguage()

  return (
    <div className="mx-auto max-w-lg rounded-2xl border border-amber-500/30 bg-amber-500/5 p-10 text-center text-white">
      <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full border border-amber-500/40 bg-amber-500/10">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="text-amber-200">
          <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
          <path d="M7 11V7a5 5 0 0 1 10 0v4" />
        </svg>
      </div>
      <h2 className="text-lg font-semibold">{t('observe.moduleUnavailable.title')}</h2>
      <p className="mt-3 text-sm text-white/60">{t('observe.moduleUnavailable.description')}</p>
    </div>
  )
}
