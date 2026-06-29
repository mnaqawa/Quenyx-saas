import { Link, Outlet, useLocation } from 'react-router-dom'
import { PageHeader } from '../components/observe/PageHeader'
import { useLanguage } from '../i18n/LanguageContext'

interface TabDef {
  key: string
  path: string
  labelKey: string
}

/** All 15 Unified AI Workspace surfaces (Sprint 20), in display order. */
const TABS: TabDef[] = [
  { key: 'overview', path: '/ai-workspace/overview', labelKey: 'aiWorkspace.nav.overview' },
  { key: 'chat', path: '/ai-workspace/chat', labelKey: 'aiWorkspace.nav.chat' },
  { key: 'conversations', path: '/ai-workspace/conversations', labelKey: 'aiWorkspace.nav.conversations' },
  { key: 'history', path: '/ai-workspace/history', labelKey: 'aiWorkspace.nav.history' },
  { key: 'activity', path: '/ai-workspace/activity', labelKey: 'aiWorkspace.nav.activity' },
  { key: 'memory', path: '/ai-workspace/memory', labelKey: 'aiWorkspace.nav.memory' },
  { key: 'templates', path: '/ai-workspace/prompt-templates', labelKey: 'aiWorkspace.nav.templates' },
  { key: 'skills', path: '/ai-workspace/skills', labelKey: 'aiWorkspace.nav.skills' },
  { key: 'capabilities', path: '/ai-workspace/capabilities', labelKey: 'aiWorkspace.nav.capabilities' },
  { key: 'usage', path: '/ai-workspace/usage', labelKey: 'aiWorkspace.nav.usage' },
  { key: 'costs', path: '/ai-workspace/costs', labelKey: 'aiWorkspace.nav.costs' },
  { key: 'providers', path: '/ai-workspace/providers', labelKey: 'aiWorkspace.nav.providers' },
  { key: 'permissions', path: '/ai-workspace/permissions', labelKey: 'aiWorkspace.nav.permissions' },
  { key: 'administration', path: '/ai-workspace/administration', labelKey: 'aiWorkspace.nav.administration' },
  { key: 'notifications', path: '/ai-workspace/notifications', labelKey: 'aiWorkspace.nav.notifications' },
]

export default function AiWorkspaceLayout() {
  const { t } = useLanguage()
  const location = useLocation()

  const isActive = (path: string): boolean =>
    location.pathname === path || location.pathname.startsWith(`${path}/`)

  return (
    <div>
      <PageHeader title={t('aiWorkspace.title')} subtitle={t('aiWorkspace.subtitle')} />

      <nav className="mb-6 flex flex-wrap gap-2 border-b border-white/10 pb-3" aria-label={t('aiWorkspace.title')}>
        {TABS.map((tab) => (
          <Link
            key={tab.key}
            to={tab.path}
            className={[
              'rounded-full px-3 py-1.5 text-xs font-medium transition',
              isActive(tab.path)
                ? 'bg-sky-500 text-white'
                : 'text-white/70 hover:bg-white/10 hover:text-white',
            ].join(' ')}
          >
            {t(tab.labelKey)}
          </Link>
        ))}
      </nav>

      <Outlet />
    </div>
  )
}
