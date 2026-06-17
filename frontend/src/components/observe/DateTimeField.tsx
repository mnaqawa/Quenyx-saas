import { useEffect, useMemo, useRef, useState } from 'react'
import { useLanguage } from '../../i18n/LanguageContext'

function pad2(n: number): string {
  return String(n).padStart(2, '0')
}

function toLocalDateTimeValue(date: Date): string {
  return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}T${pad2(date.getHours())}:${pad2(date.getMinutes())}`
}

function parseValue(value?: string): Date | null {
  if (!value) return null
  const normalized = value.includes('T') ? value : value.replace(' ', 'T')
  const d = new Date(normalized)
  return Number.isNaN(d.getTime()) ? null : d
}

function startOfMonth(year: number, month: number): Date {
  return new Date(year, month, 1)
}

function daysInMonth(year: number, month: number): number {
  return new Date(year, month + 1, 0).getDate()
}

const WEEKDAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as const

export function DateTimeField({
  value,
  onChange,
  label,
  placeholder,
}: {
  value?: string
  onChange: (value: string | undefined) => void
  label: string
  placeholder?: string
}) {
  const { t, language } = useLanguage()
  const rootRef = useRef<HTMLDivElement>(null)
  const [open, setOpen] = useState(false)

  const parsed = useMemo(() => parseValue(value), [value])
  const [viewYear, setViewYear] = useState(() => parsed?.getFullYear() ?? new Date().getFullYear())
  const [viewMonth, setViewMonth] = useState(() => parsed?.getMonth() ?? new Date().getMonth())
  const [draftDate, setDraftDate] = useState<Date | null>(parsed)
  const [hour, setHour] = useState(() => (parsed ? pad2(parsed.getHours()) : '00'))
  const [minute, setMinute] = useState(() => (parsed ? pad2(parsed.getMinutes()) : '00'))

  useEffect(() => {
    if (!open) return
    const p = parseValue(value)
    setViewYear(p?.getFullYear() ?? new Date().getFullYear())
    setViewMonth(p?.getMonth() ?? new Date().getMonth())
    setDraftDate(p)
    setHour(p ? pad2(p.getHours()) : '00')
    setMinute(p ? pad2(p.getMinutes()) : '00')
  }, [open, value])

  useEffect(() => {
    if (!open) return
    const onDocClick = (e: MouseEvent) => {
      if (rootRef.current && !rootRef.current.contains(e.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', onDocClick)
    return () => document.removeEventListener('mousedown', onDocClick)
  }, [open])

  const locale = language === 'ar' ? 'ar-SA' : 'en-US'
  const monthLabel = new Date(viewYear, viewMonth, 1).toLocaleDateString(locale, {
    month: 'long',
    year: 'numeric',
  })

  const displayValue = parsed
    ? parsed.toLocaleString(locale, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      })
    : ''

  const firstDay = startOfMonth(viewYear, viewMonth).getDay()
  const totalDays = daysInMonth(viewYear, viewMonth)
  const cells: Array<{ day: number | null; key: string }> = []
  for (let i = 0; i < firstDay; i++) cells.push({ day: null, key: `e-${i}` })
  for (let d = 1; d <= totalDays; d++) cells.push({ day: d, key: `d-${d}` })

  const shiftMonth = (delta: number) => {
    const d = new Date(viewYear, viewMonth + delta, 1)
    setViewYear(d.getFullYear())
    setViewMonth(d.getMonth())
  }

  const selectDay = (day: number) => {
    const next = new Date(viewYear, viewMonth, day, Number(hour), Number(minute), 0, 0)
    setDraftDate(next)
  }

  const apply = () => {
    if (!draftDate) return
    const next = new Date(
      draftDate.getFullYear(),
      draftDate.getMonth(),
      draftDate.getDate(),
      Number(hour),
      Number(minute),
      0,
      0
    )
    onChange(toLocalDateTimeValue(next))
    setOpen(false)
  }

  const clear = () => {
    onChange(undefined)
    setDraftDate(null)
    setOpen(false)
  }

  const setToday = () => {
    const now = new Date()
    setViewYear(now.getFullYear())
    setViewMonth(now.getMonth())
    setDraftDate(now)
    setHour(pad2(now.getHours()))
    setMinute(pad2(now.getMinutes()))
  }

  const isSameDay = (a: Date | null, y: number, m: number, day: number) =>
    a != null &&
    a.getFullYear() === y &&
    a.getMonth() === m &&
    a.getDate() === day

  const today = new Date()

  return (
    <div ref={rootRef} className="relative min-w-[200px]">
      <span className="mb-1 block text-xs font-medium text-white/60">{label}</span>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className="flex w-full items-center justify-between gap-2 rounded-lg border border-white/15 bg-white/5 px-3 py-2 text-left text-sm text-white transition hover:border-white/25 hover:bg-white/[0.07]"
      >
        <span className={displayValue ? 'text-white' : 'text-white/40'}>
          {displayValue || placeholder || t('alerts.datetime.placeholder')}
        </span>
        <svg className="h-4 w-4 shrink-0 text-white/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
          <rect x="3" y="4" width="18" height="18" rx="2" />
          <path d="M16 2v4M8 2v4M3 10h18" />
        </svg>
      </button>

      {open && (
        <div className="absolute start-0 z-50 mt-2 w-[min(100vw-2rem,320px)] rounded-xl border border-white/15 bg-[#121a24] p-4 shadow-2xl shadow-black/40">
          <div className="mb-3 flex items-center justify-between gap-2">
            <button
              type="button"
              onClick={() => shiftMonth(-1)}
              className="rounded-md border border-white/10 px-2 py-1 text-sm text-white/70 hover:bg-white/10"
              aria-label={t('alerts.datetime.prevMonth')}
            >
              ‹
            </button>
            <div className="text-sm font-semibold text-white">{monthLabel}</div>
            <button
              type="button"
              onClick={() => shiftMonth(1)}
              className="rounded-md border border-white/10 px-2 py-1 text-sm text-white/70 hover:bg-white/10"
              aria-label={t('alerts.datetime.nextMonth')}
            >
              ›
            </button>
          </div>

          <div className="mb-2 grid grid-cols-7 gap-1 text-center text-[10px] font-medium uppercase tracking-wide text-white/40">
            {WEEKDAY_KEYS.map((key) => (
              <div key={key}>{t(`alerts.datetime.weekday.${key}`)}</div>
            ))}
          </div>

          <div className="grid grid-cols-7 gap-1">
            {cells.map(({ day, key }) =>
              day == null ? (
                <div key={key} className="h-8" />
              ) : (
                <button
                  key={key}
                  type="button"
                  onClick={() => selectDay(day)}
                  className={`h-8 rounded-md text-sm transition ${
                    isSameDay(draftDate, viewYear, viewMonth, day)
                      ? 'bg-sky-600 font-semibold text-white'
                      : 'text-white/80 hover:bg-white/10'
                  } ${
                    today.getFullYear() === viewYear &&
                    today.getMonth() === viewMonth &&
                    today.getDate() === day &&
                    !isSameDay(draftDate, viewYear, viewMonth, day)
                      ? 'ring-1 ring-sky-500/50'
                      : ''
                  }`}
                >
                  {day}
                </button>
              )
            )}
          </div>

          <div className="mt-4 border-t border-white/10 pt-3">
            <div className="mb-2 text-xs font-medium text-white/60">{t('alerts.datetime.time')}</div>
            <div className="flex items-center gap-2">
              <input
                type="number"
                min={0}
                max={23}
                value={hour}
                onChange={(e) => setHour(pad2(Math.min(23, Math.max(0, Number(e.target.value) || 0))))}
                className="w-16 rounded-md border border-white/15 bg-white/5 px-2 py-1.5 text-center text-sm text-white"
              />
              <span className="text-white/50">:</span>
              <input
                type="number"
                min={0}
                max={59}
                value={minute}
                onChange={(e) => setMinute(pad2(Math.min(59, Math.max(0, Number(e.target.value) || 0))))}
                className="w-16 rounded-md border border-white/15 bg-white/5 px-2 py-1.5 text-center text-sm text-white"
              />
              <button
                type="button"
                onClick={setToday}
                className="ms-auto rounded-md border border-white/10 px-2 py-1 text-xs text-white/70 hover:bg-white/10"
              >
                {t('alerts.datetime.today')}
              </button>
            </div>
          </div>

          <div className="mt-4 flex justify-end gap-2">
            <button
              type="button"
              onClick={clear}
              className="rounded-lg border border-white/15 px-3 py-1.5 text-xs text-white/70 hover:bg-white/5"
            >
              {t('alerts.datetime.clear')}
            </button>
            <button
              type="button"
              disabled={!draftDate}
              onClick={apply}
              className="rounded-lg bg-sky-600 px-3 py-1.5 text-xs font-medium text-white disabled:opacity-40"
            >
              {t('alerts.datetime.apply')}
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
