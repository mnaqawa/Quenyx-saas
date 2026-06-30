import { Link, Outlet, useLocation } from 'react-router-dom'
import { PageHeader } from '../components/observe/PageHeader'
import { useLanguage } from '../i18n/LanguageContext'

type TabGroup = 'workspace' | 'intelligence' | 'operations' | 'administration'

interface TabDef {
  key: string
  path: string
  labelKey: string
  group: TabGroup
}

/**
 * Quenyx AI surfaces (Sprint 20), grouped for an enterprise control-center layout (v1.0.0). Routes
 * remain under /ai-workspace/* for backward compatibility; /quenyx-ai/* redirects here.
 */
const TABS: TabDef[] = [
  { key: 'overview', path: '/ai-workspace/overview', labelKey: 'aiWorkspace.nav.overview', group: 'workspace' },
  { key: 'chat', path: '/ai-workspace/chat', labelKey: 'aiWorkspace.nav.chat', group: 'workspace' },
  { key: 'conversations', path: '/ai-workspace/conversations', labelKey: 'aiWorkspace.nav.conversations', group: 'workspace' },
  { key: 'history', path: '/ai-workspace/history', labelKey: 'aiWorkspace.nav.history', group: 'workspace' },
  { key: 'activity', path: '/ai-workspace/activity', labelKey: 'aiWorkspace.nav.activity', group: 'workspace' },

  { key: 'skills', path: '/ai-workspace/skills', labelKey: 'aiWorkspace.nav.skills', group: 'intelligence' },
  { key: 'capabilities', path: '/ai-workspace/capabilities', labelKey: 'aiWorkspace.nav.capabilities', group: 'intelligence' },
  { key: 'memory', path: '/ai-workspace/memory', labelKey: 'aiWorkspace.nav.memory', group: 'intelligence' },
  { key: 'templates', path: '/ai-workspace/prompt-templates', labelKey: 'aiWorkspace.nav.templates', group: 'intelligence' },

  { key: 'usage', path: '/ai-workspace/usage', labelKey: 'aiWorkspace.nav.usage', group: 'operations' },
  { key: 'costs', path: '/ai-workspace/costs', labelKey: 'aiWorkspace.nav.costs', group: 'operations' },
  { key: 'providers', path: '/ai-workspace/providers', labelKey: 'aiWorkspace.nav.providers', group: 'operations' },

  { key: 'permissions', path: '/ai-workspace/permissions', labelKey: 'aiWorkspace.nav.permissions', group: 'administration' },
  { key: 'administration', path: '/ai-workspace/administration', labelKey: 'aiWorkspace.nav.administration', group: 'administration' },
  { key: 'notifications', path: '/ai-workspace/notifications', labelKey: 'aiWorkspace.nav.notifications', group: 'administration' },
]

const GROUP_ORDER: TabGroup[] = ['workspace', 'intelligence', 'operations', 'administration']

export default function AiWorkspaceLayout() {
  const { t } = useLanguage()
  const location = useLocation()

  const isActive = (path: string): boolean =>
    location.pathname === path || location.pathname.startsWith(`${path}/`)

  return (
    <div>
      <PageHeader title={t('aiWorkspace.title')} subtitle={t('aiWorkspace.subtitle')} />

      <nav className="mb-6 -mx-1 overflow-x-auto pb-3" aria-label={t('aiWorkspace.title')}>
        <div className="flex min-w-max items-stretch gap-4 px-1">
          {GROUP_ORDER.map((group, gi) => (
            <div key={group} className="flex items-center gap-2">
              {gi > 0 ? <span className="mx-1 h-6 w-px bg-white/10" aria-hidden /> : null}
              <span className="hidden text-[10px] font-semibold uppercase tracking-wider text-white/30 lg:inline">
                {t(`aiWorkspace.group.${group}`)}
              </span>
              <div className="flex gap-1.5">
                {TABS.filter((tab) => tab.group === group).map((tab) => (
                  <Link
                    key={tab.key}
                    to={tab.path}
                    aria-current={isActive(tab.path) ? 'page' : undefined}
                    className={[
                      'whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-medium transition',
                      isActive(tab.path)
                        ? 'bg-sky-500 text-white shadow-sm shadow-sky-500/30'
                        : 'text-white/70 hover:bg-white/10 hover:text-white',
                    ].join(' ')}
                  >
                    {t(tab.labelKey)}
                  </Link>
                ))}
              </div>
            </div>
          ))}
        </div>
      </nav>

      <Outlet />
    </div>
  )
}
