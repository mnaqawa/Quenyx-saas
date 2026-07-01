import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useLanguage } from '../../i18n/LanguageContext'
import { useWorkspaceContext } from '../../workspaces/WorkspaceContext'
import { modules, routesByModule, isModuleNavigable, getModuleBasePath } from '../../constants/platformRegistry'
import { ModuleIcon } from '../icons/ModuleIcons'

const RECENT_KEY = 'quenyx.recent_routes'

export interface CommandPaletteProps {
  open: boolean
  onClose: () => void
  onAskAi?: () => void
}

interface CommandItem {
  id: string
  label: string
  group: string
  keywords: string
  action: () => void
}

function loadRecent(): string[] {
  try {
    const raw = localStorage.getItem(RECENT_KEY)
    return raw ? (JSON.parse(raw) as string[]) : []
  } catch {
    return []
  }
}

function pushRecent(path: string) {
  const prev = loadRecent().filter((p) => p !== path)
  localStorage.setItem(RECENT_KEY, JSON.stringify([path, ...prev].slice(0, 8)))
}

export function CommandPalette({ open, onClose, onAskAi }: CommandPaletteProps) {
  const { t } = useLanguage()
  const navigate = useNavigate()
  const inputRef = useRef<HTMLInputElement>(null)
  const [query, setQuery] = useState('')
  const [activeIndex, setActiveIndex] = useState(0)
  const { selectedWorkspaceId } = useWorkspaceContext()

  useEffect(() => {
    if (!open) return
    setQuery('')
    setActiveIndex(0)
    const id = window.setTimeout(() => inputRef.current?.focus(), 0)
    return () => window.clearTimeout(id)
  }, [open])

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [open, onClose])

  const go = useCallback(
    (path: string) => {
      pushRecent(path)
      navigate(path)
      onClose()
    },
    [navigate, onClose],
  )

  const items = useMemo<CommandItem[]>(() => {
    const ws = selectedWorkspaceId ? String(selectedWorkspaceId) : null
    const list: CommandItem[] = [
      { id: 'dash', label: t('nav.dashboard'), group: t('commandPalette.group.navigate'), keywords: 'dashboard home', action: () => go('/dashboard') },
      { id: 'workspaces', label: t('nav.projects'), group: t('commandPalette.group.navigate'), keywords: 'workspaces projects', action: () => go('/app/workspaces') },
      { id: 'ai', label: t('nav.aiWorkspace'), group: t('commandPalette.group.navigate'), keywords: 'ai quenyx chat', action: () => go('/ai-workspace/overview') },
      { id: 'integrations', label: t('nav.integrations'), group: t('commandPalette.group.navigate'), keywords: 'integrations agents', action: () => go('/integrations') },
      { id: 'help', label: t('nav.helpCenter'), group: t('commandPalette.group.navigate'), keywords: 'help docs support', action: () => go('/help-center') },
      { id: 'settings', label: t('projects.settings'), group: t('commandPalette.group.settings'), keywords: 'settings access', action: () => go('/settings/access') },
    ]

    if (onAskAi) {
      list.unshift({
        id: 'ask-ai',
        label: t('ai.askQuenyx'),
        group: t('commandPalette.group.actions'),
        keywords: 'ai agent ask copilot',
        action: () => {
          onClose()
          onAskAi()
        },
      })
    }

    if (ws) {
      list.push({
        id: 'create-ticket',
        label: t('commandPalette.createTicket'),
        group: t('commandPalette.group.actions'),
        keywords: 'ticket support create',
        action: () => go(`/app/workspaces/${ws}/qynsupport`),
      })
      list.push({
        id: 'add-host',
        label: t('commandPalette.addHost'),
        group: t('commandPalette.group.actions'),
        keywords: 'host targets monitoring',
        action: () => go(`/app/workspaces/${ws}/observe/targets`),
      })
      list.push({
        id: 'knowledge-search',
        label: t('commandPalette.searchKnowledge'),
        group: t('commandPalette.group.search'),
        keywords: 'knowledge search qynknow',
        action: () => go(`/app/workspaces/${ws}/qynknow/search`),
      })

      for (const mod of modules.filter((m) => isModuleNavigable(m.key))) {
        const routes = routesByModule[mod.key] ?? []
        const path =
          routes.length > 0
            ? routes[0].path.replace(':id', ws)
            : getModuleBasePath(mod.key, ws)
        list.push({
          id: `mod-${mod.key}`,
          label: mod.displayName,
          group: t('commandPalette.group.modules'),
          keywords: `${mod.key} ${mod.displayName}`,
          action: () => go(path),
        })
      }
    }

    const recent = loadRecent()
    for (const path of recent) {
      list.push({
        id: `recent-${path}`,
        label: path,
        group: t('commandPalette.group.recent'),
        keywords: path,
        action: () => go(path),
      })
    }

    return list
  }, [go, onAskAi, onClose, selectedWorkspaceId, t])

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase()
    if (!q) return items.slice(0, 24)
    return items.filter((item) => item.label.toLowerCase().includes(q) || item.keywords.toLowerCase().includes(q)).slice(0, 24)
  }, [items, query])

  useEffect(() => {
    setActiveIndex(0)
  }, [query])

  const runActive = () => {
    const item = filtered[activeIndex]
    if (item) item.action()
  }

  if (!open) return null

  return (
    <div className="fixed inset-0 z-[100] flex items-start justify-center bg-black/60 px-4 pt-[12vh]" role="presentation" onMouseDown={onClose}>
      <div
        role="dialog"
        aria-modal="true"
        aria-label={t('commandPalette.title')}
        className="w-full max-w-xl overflow-hidden rounded-2xl border border-white/10 bg-[#121820] shadow-2xl"
        onMouseDown={(e) => e.stopPropagation()}
      >
        <div className="flex items-center gap-2 border-b border-white/10 px-4 py-3">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" className="text-white/40">
            <circle cx="11" cy="11" r="8" />
            <path d="M21 21l-4.35-4.35" />
          </svg>
          <input
            ref={inputRef}
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'ArrowDown') {
                e.preventDefault()
                setActiveIndex((i) => Math.min(i + 1, filtered.length - 1))
              } else if (e.key === 'ArrowUp') {
                e.preventDefault()
                setActiveIndex((i) => Math.max(i - 1, 0))
              } else if (e.key === 'Enter') {
                e.preventDefault()
                runActive()
              }
            }}
            placeholder={t('commandPalette.placeholder')}
            className="min-w-0 flex-1 bg-transparent text-sm text-white placeholder:text-white/35 focus:outline-none"
          />
          <kbd className="hidden rounded border border-white/10 bg-white/5 px-1.5 py-0.5 text-[10px] text-white/40 sm:inline">Esc</kbd>
        </div>
        <ul className="max-h-[50vh] overflow-y-auto py-2" role="listbox">
          {filtered.length === 0 ? (
            <li className="px-4 py-6 text-center text-sm text-white/45">{t('commandPalette.empty')}</li>
          ) : (
            filtered.map((item, index) => (
              <li key={item.id}>
                <button
                  type="button"
                  role="option"
                  aria-selected={index === activeIndex}
                  onMouseEnter={() => setActiveIndex(index)}
                  onClick={() => item.action()}
                  className={[
                    'flex w-full items-center gap-3 px-4 py-2.5 text-start text-sm transition',
                    index === activeIndex ? 'bg-sky-500/15 text-white' : 'text-white/75 hover:bg-white/5',
                  ].join(' ')}
                >
                  <ModuleIcon moduleKey="qynva" size={14} className="shrink-0 opacity-60" />
                  <span className="min-w-0 flex-1 truncate">{item.label}</span>
                  <span className="shrink-0 text-[10px] text-white/35">{item.group}</span>
                </button>
              </li>
            ))
          )}
        </ul>
      </div>
    </div>
  )
}

export function useCommandPaletteShortcut(onOpen: () => void) {
  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault()
        onOpen()
      }
    }
    document.addEventListener('keydown', handler)
    return () => document.removeEventListener('keydown', handler)
  }, [onOpen])
}
