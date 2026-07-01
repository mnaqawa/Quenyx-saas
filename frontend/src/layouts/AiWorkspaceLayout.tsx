import { Link, Outlet, useLocation } from 'react-router-dom'
import { PageHeader } from '../components/observe/PageHeader'
import { useLanguage } from '../i18n/LanguageContext'

type TabGroup = 'workspace' | 'ai' | 'administration'

interface TabDef {
  key: string
  path: string
  labelKey: string
  group: TabGroup
}

const TABS: TabDef[] = [
  { key: 'overview', path: '/ai-workspace/overview', labelKey: 'aiWorkspace.nav.overview', group: 'workspace' },
  { key: 'chat', path: '/ai-workspace/chat', labelKey: 'aiWorkspace.nav.chat', group: 'workspace' },
  { key: 'conversations', path: '/ai-workspace/conversations', labelKey: 'aiWorkspace.nav.conversations', group: 'workspace' },
  { key: 'history', path: '/ai-workspace/history', labelKey: 'aiWorkspace.nav.history', group: 'workspace' },
  { key: 'activity', path: '/ai-workspace/activity', labelKey: 'aiWorkspace.nav.activity', group: 'workspace' },

  { key: 'skills', path: '/ai-workspace/skills', labelKey: 'aiWorkspace.nav.skills', group: 'ai' },
  { key: 'capabilities', path: '/ai-workspace/capabilities', labelKey: 'aiWorkspace.nav.capabilities', group: 'ai' },
  { key: 'memory', path: '/ai-workspace/memory', labelKey: 'aiWorkspace.nav.memory', group: 'ai' },
  { key: 'templates', path: '/ai-workspace/prompt-templates', labelKey: 'aiWorkspace.nav.templates', group: 'ai' },

  { key: 'providers', path: '/ai-workspace/providers', labelKey: 'aiWorkspace.nav.providers', group: 'administration' },
  { key: 'permissions', path: '/ai-workspace/permissions', labelKey: 'aiWorkspace.nav.permissions', group: 'administration' },
  { key: 'usage', path: '/ai-workspace/usage', labelKey: 'aiWorkspace.nav.usage', group: 'administration' },
  { key: 'costs', path: '/ai-workspace/costs', labelKey: 'aiWorkspace.nav.costs', group: 'administration' },
  { key: 'notifications', path: '/ai-workspace/notifications', labelKey: 'aiWorkspace.nav.notifications', group: 'administration' },
]

const GROUP_ORDER: TabGroup[] = ['workspace', 'ai', 'administration']

const GROUP_I18N: Record<TabGroup, string> = {
  workspace: 'aiWorkspace.group.workspace',
  ai: 'aiWorkspace.group.intelligence',
  administration: 'aiWorkspace.group.administration',
}

export default function AiWorkspaceLayout() {
  const { t } = useLanguage()
  const location = useLocation()

  const isActive = (path: string): boolean =>
    location.pathname === path || location.pathname.startsWith(`${path}/`)

  return (
    <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
      <aside className="w-full shrink-0 lg:w-56 xl:w-60" aria-label={t('aiWorkspace.navLabel')}>
        <nav className="space-y-5 rounded-2xl border border-white/10 bg-[#0f151d] p-3">
          {GROUP_ORDER.map((group) => (
            <div key={group}>
              <p className="px-2 pb-1.5 text-[10px] font-semibold uppercase tracking-wider text-white/35">
                {t(GROUP_I18N[group])}
              </p>
              <ul className="space-y-0.5">
                {TABS.filter((tab) => tab.group === group).map((tab) => (
                  <li key={tab.key}>
                    <Link
                      to={tab.path}
                      aria-current={isActive(tab.path) ? 'page' : undefined}
                      className={[
                        'block rounded-lg px-3 py-2 text-sm font-medium transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-500',
                        isActive(tab.path)
                          ? 'bg-sky-500/20 text-white'
                          : 'text-white/65 hover:bg-white/5 hover:text-white',
                      ].join(' ')}
                    >
                      {t(tab.labelKey)}
                    </Link>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </nav>
      </aside>

      <div className="min-w-0 flex-1">
        <PageHeader title={t('aiWorkspace.title')} subtitle={t('aiWorkspace.subtitle')} />
        <Outlet />
      </div>
    </div>
  )
}
