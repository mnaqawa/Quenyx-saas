import { useEffect, useMemo, useRef, useState } from 'react'
import { useLanguage } from '../../i18n/LanguageContext'
import type { ServiceDefinition } from '../../types/observe'

const CATEGORY_ORDER = [
  'Connectivity',
  'Web & SSL',
  'System Resources',
  'Processes & Users',
  'Databases',
  'Mail & Directory',
  'Custom',
  'Other',
] as const

function categoryForDefinition(def: ServiceDefinition): string {
  const key = def.service_key
  if (key === 'plugin') return 'Custom'
  if (['ping', 'tcp_port', 'ssh', 'dns', 'ntp_time', 'udp'].includes(key)) return 'Connectivity'
  if (['http', 'ssl_validity'].includes(key)) return 'Web & SSL'
  if (['cpu', 'memory', 'disk', 'inodes', 'load', 'swap', 'uptime'].includes(key)) return 'System Resources'
  if (['users', 'procs'].includes(key)) return 'Processes & Users'
  if (['mysql', 'pgsql', 'mysql_query', 'oracle'].includes(key)) return 'Databases'
  if (['smtp', 'imap', 'pop', 'ldap', 'ftp'].includes(key)) return 'Mail & Directory'
  return 'Other'
}

interface ObserveServiceTypeSelectProps {
  value: string
  options: ServiceDefinition[]
  onChange: (serviceKey: string) => void
  disabled?: boolean
  error?: string
}

export function ObserveServiceTypeSelect({
  value,
  options,
  onChange,
  disabled = false,
  error,
}: ObserveServiceTypeSelectProps) {
  const { t } = useLanguage()
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const rootRef = useRef<HTMLDivElement>(null)
  const searchRef = useRef<HTMLInputElement>(null)

  const selected = useMemo(
    () => options.find((d) => d.service_key === value) ?? null,
    [options, value]
  )

  const filteredGroups = useMemo(() => {
    const q = query.trim().toLowerCase()
    const filtered = q
      ? options.filter((d) => {
          const haystack = `${d.display_name} ${d.service_key} ${d.description ?? ''}`.toLowerCase()
          return haystack.includes(q)
        })
      : options

    const grouped = new Map<string, ServiceDefinition[]>()
    filtered.forEach((def) => {
      const category = categoryForDefinition(def)
      if (!grouped.has(category)) grouped.set(category, [])
      grouped.get(category)!.push(def)
    })

    grouped.forEach((items) => {
      items.sort((a, b) => a.display_name.localeCompare(b.display_name))
    })

    return CATEGORY_ORDER.filter((cat) => grouped.has(cat)).map((cat) => ({
      category: cat,
      items: grouped.get(cat) ?? [],
    }))
  }, [options, query])

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

  useEffect(() => {
    if (open) {
      window.requestAnimationFrame(() => searchRef.current?.focus())
    } else {
      setQuery('')
    }
  }, [open])

  const borderClass = error ? 'border-rose-500/50 bg-rose-500/10' : 'border-white/10 bg-white/5'

  return (
    <div ref={rootRef} className="relative min-w-0 w-full flex-1 sm:min-w-[12rem]">
      <button
        type="button"
        disabled={disabled}
        onClick={() => setOpen((v) => !v)}
        className={`flex w-full items-center justify-between gap-2 rounded border px-2 py-1 text-xs text-white disabled:opacity-50 ${borderClass}`}
        aria-haspopup="listbox"
        aria-expanded={open}
      >
        <span className="truncate text-start">
          {selected ? selected.display_name : t('targets.selectServiceCheckType')}
        </span>
        <span className="text-white/50">{open ? '▴' : '▾'}</span>
      </button>

      {open && (
        <div className="absolute z-50 mt-1 w-full min-w-[16rem] overflow-hidden rounded-md border border-white/15 bg-[#0f172a] shadow-xl">
          <div className="border-b border-white/10 p-2">
            <input
              ref={searchRef}
              type="search"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder={t('targets.searchServiceType')}
              className="w-full rounded border border-white/10 bg-white/5 px-2 py-1.5 text-xs text-white placeholder:text-white/40"
            />
          </div>
          <div className="max-h-64 overflow-y-auto py-1" role="listbox">
            {filteredGroups.length === 0 ? (
              <div className="px-3 py-2 text-xs text-white/50">{t('targets.noServiceTypeMatch')}</div>
            ) : (
              filteredGroups.map(({ category, items }) => (
                <div key={category}>
                  <div className="px-3 py-1 text-[10px] font-semibold uppercase tracking-wide text-white/40">
                    {category}
                  </div>
                  {items.map((def) => {
                    const active = def.service_key === value
                    return (
                      <button
                        key={def.service_key}
                        type="button"
                        role="option"
                        aria-selected={active}
                        onClick={() => {
                          onChange(def.service_key)
                          setOpen(false)
                        }}
                        className={`block w-full px-3 py-2 text-start text-xs hover:bg-white/10 ${
                          active ? 'bg-sky-500/15 text-sky-200' : 'text-white/90'
                        }`}
                      >
                        <div className="font-medium">{def.display_name}</div>
                        {def.description && (
                          <div className="mt-0.5 line-clamp-2 text-[10px] text-white/50">{def.description}</div>
                        )}
                      </button>
                    )
                  })}
                </div>
              ))
            )}
          </div>
        </div>
      )}
      {error && <div className="mt-1 text-xs text-rose-400">{error}</div>}
    </div>
  )
}
