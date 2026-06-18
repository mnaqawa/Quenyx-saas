/* eslint-disable react-refresh/only-export-components -- hook colocated with provider for i18n */
import { createContext, useContext, useEffect, useMemo, useState } from 'react'
import { translations, Language } from './translations'

interface LanguageContextValue {
  language: Language
  dir: 'ltr' | 'rtl'
  setLanguage: (language: Language) => void
  t: (key: string) => string
}

const LanguageContext = createContext<LanguageContextValue | undefined>(undefined)

const STORAGE_KEY = 'quenyx.language'

export function LanguageProvider({ children }: { children: React.ReactNode }) {
  const [language, setLanguage] = useState<Language>(() => {
    const stored = localStorage.getItem(STORAGE_KEY)
    if (stored === 'ar' || stored === 'en') {
      return stored
    }
    return 'en'
  })

  useEffect(() => {
    localStorage.setItem(STORAGE_KEY, language)
    document.documentElement.lang = language
    document.documentElement.dir = language === 'ar' ? 'rtl' : 'ltr'
  }, [language])

  const value = useMemo<LanguageContextValue>(() => {
    const dir = language === 'ar' ? 'rtl' : 'ltr'
    return {
      language,
      dir,
      setLanguage,
      t: (key: string) => translations[language][key] ?? key,
    }
  }, [language])

  return <LanguageContext.Provider value={value}>{children}</LanguageContext.Provider>
}

export function useLanguage() {
  const context = useContext(LanguageContext)
  if (!context) {
    throw new Error('useLanguage must be used within LanguageProvider')
  }
  return context
}
