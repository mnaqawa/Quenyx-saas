import { useState } from 'react'
import { useLanguage } from '../../i18n/LanguageContext'

interface ObservePasswordInputProps {
  value: string
  onChange: (value: string | null) => void
  disabled?: boolean
  placeholder?: string
}

export function ObservePasswordInput({
  value,
  onChange,
  disabled = false,
  placeholder,
}: ObservePasswordInputProps) {
  const { t } = useLanguage()
  const [visible, setVisible] = useState(false)

  return (
    <div className="flex gap-2">
      <input
        type={visible ? 'text' : 'password'}
        value={value}
        onChange={(e) => onChange(e.target.value || null)}
        disabled={disabled}
        placeholder={placeholder}
        autoComplete="new-password"
        className="min-w-0 flex-1 rounded border border-white/10 bg-white/5 px-2 py-1 text-xs text-white placeholder:text-white/40 disabled:opacity-50"
      />
      <button
        type="button"
        onClick={() => setVisible((v) => !v)}
        disabled={disabled}
        className="shrink-0 rounded border border-white/10 bg-white/5 px-2 py-1 text-[10px] text-white/70 hover:bg-white/10 disabled:opacity-50"
      >
        {visible ? t('targets.hidePassword') : t('targets.showPassword')}
      </button>
    </div>
  )
}
