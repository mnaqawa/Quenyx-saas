import { useEffect, useRef, useState } from 'react'
import { useLanguage } from '../i18n/LanguageContext'
import type { Language } from '../i18n/translations'

const OPTIONS: Array<{ code: Language; label: string }> = [
  { code: 'en', label: 'English' },
  { code: 'ar', label: 'العربية' },
]

function GlobeIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path
        d="M12 2a10 10 0 100 20 10 10 0 000-20z"
        stroke="currentColor"
        strokeWidth="1.6"
      />
      <path
        d="M2 12h20M12 2c3 3 3 15 0 20M12 2c-3 3-3 15 0 20"
        stroke="currentColor"
        strokeWidth="1.2"
      />
    </svg>
  )
}

export function LanguageSwitcher() {
  const { language, setLanguage, t } = useLanguage()
  const [open, setOpen] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return

    const handleClickOutside = (event: MouseEvent) => {
      if (rootRef.current && !rootRef.current.contains(event.target as Node)) {
        setOpen(false)
      }
    }
    const handleEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false)
    }

    document.addEventListener('mousedown', handleClickOutside)
    document.addEventListener('keydown', handleEscape)
    return () => {
      document.removeEventListener('mousedown', handleClickOutside)
      document.removeEventListener('keydown', handleEscape)
    }
  }, [open])

  const selectLanguage = (code: Language) => {
    setLanguage(code)
    setOpen(false)
  }

  return (
    <div className="relative" ref={rootRef} data-tour="tour-language">
      <button
        type="button"
        onClick={() => setOpen((prev) => !prev)}
        aria-label={t('language.select')}
        aria-haspopup="listbox"
        aria-expanded={open}
        className="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-white/10 bg-white/5 text-white/70 transition hover:bg-white/10 hover:text-white"
      >
        <GlobeIcon />
      </button>

      {open ? (
        <div
          role="listbox"
          aria-label={t('language.select')}
          className="absolute end-0 z-50 mt-1 min-w-[9.5rem] overflow-hidden rounded-lg border border-white/10 bg-[#161c24] py-1 shadow-xl shadow-black/40"
        >
          {OPTIONS.map((option) => {
            const selected = language === option.code
            return (
              <button
                key={option.code}
                type="button"
                role="option"
                aria-selected={selected}
                onClick={() => selectLanguage(option.code)}
                className={`flex w-full items-center gap-2 px-3 py-2 text-start text-xs transition ${
                  selected
                    ? 'bg-white/10 text-white'
                    : 'text-white/70 hover:bg-white/5 hover:text-white'
                }`}
              >
                <span className="flex h-4 w-4 shrink-0 items-center justify-center text-sky-300">
                  {selected ? (
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
                      <polyline points="20 6 9 17 4 12" />
                    </svg>
                  ) : null}
                </span>
                <span>{option.label}</span>
              </button>
            )
          })}
        </div>
      ) : null}
    </div>
  )
}
