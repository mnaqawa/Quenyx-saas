import { Link } from 'react-router-dom'
import { useLanguage } from '../i18n/LanguageContext'
import { PageHeader } from '../components/observe/PageHeader'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'
import { useEnterpriseDashboard } from '../hooks/useEnterpriseDashboard'
import { EnterpriseModuleCard } from '../components/dashboard/EnterpriseModuleCard'
import { EmptyState } from '../components/observe/capacity/EmptyState'
import {
  IconInfrastructure,
  IconAssets,
  IconAutomation,
  IconIncidents,
  IconKnowledge,
  IconSupport,
  IconNotifications,
  IconAi,
  IconCompliance,
  IconPlatform,
} from '../components/icons/ModuleIcons'

function EnterpriseHealthBanner({
  score,
  label,
  t,
}: {
  score: number | null
  label: 'ready' | 'calculating' | 'no_data'
  t: (k: string) => string
}) {
  const display =
    label === 'ready' && score !== null
      ? `${score}`
      : label === 'calculating'
        ? t('enterpriseDashboard.healthCalculating')
        : t('enterpriseDashboard.healthNoData')

  return (
    <div className="mb-6 flex flex-col gap-4 rounded-2xl border border-white/10 bg-gradient-to-br from-[#0f151d] to-[#121820] p-5 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <p className="text-xs font-semibold uppercase tracking-wider text-white/45">{t('enterpriseDashboard.enterpriseHealth')}</p>
        <p className="mt-1 text-sm text-white/60">{t('enterpriseDashboard.enterpriseHealthDesc')}</p>
      </div>
      <div className="flex items-baseline gap-2">
        <span className="text-4xl font-semibold tabular-nums text-white">{display}</span>
        {label === 'ready' && score !== null ? <span className="text-sm text-white/45">/ 100</span> : null}
      </div>
    </div>
  )
}

export default function Dashboard() {
  const { t } = useLanguage()
  const { workspaces, selectedWorkspaceId, selectedWorkspace, allowedByKey, isLoadingWorkspaces } = useWorkspaceContext()

  const snapshot = useEnterpriseDashboard(selectedWorkspaceId, selectedWorkspace?.uuid, allowedByKey)

  const cardLabels = {
    noData: t('enterpriseDashboard.noData'),
    notConfigured: t('enterpriseDashboard.notConfigured'),
    locked: t('enterpriseDashboard.locked'),
    loading: t('common.loading'),
  }

  if (isLoadingWorkspaces) {
    return <p className="text-sm text-white/50">{t('common.loading')}</p>
  }

  if (workspaces.length === 0) {
    return (
      <EmptyState
        title={t('dashboard.noWorkspaces')}
        description={t('dashboard.noWorkspacesDesc')}
        primaryAction={
          <Link to="/app/workspaces" className="rounded-lg bg-sky-600 px-4 py-2 text-xs font-semibold text-white hover:bg-sky-500">
            {t('dashboard.createWorkspace')}
          </Link>
        }
        secondaryAction={
          <Link to="/help-center" className="rounded-lg border border-white/15 px-4 py-2 text-xs text-white/70 hover:bg-white/5">
            {t('helpCenter.quickStart')}
          </Link>
        }
      />
    )
  }

  if (!selectedWorkspaceId) {
    return (
      <EmptyState
        title={t('dashboard.selectWorkspace')}
        description={t('dashboard.selectWorkspaceDesc')}
        primaryAction={
          <Link to="/app/workspaces" className="rounded-lg bg-sky-600 px-4 py-2 text-xs font-semibold text-white hover:bg-sky-500">
            {t('nav.projects')}
          </Link>
        }
      />
    )
  }

  return (
    <div>
      <PageHeader
        title={t('enterpriseDashboard.title')}
        subtitle={t('enterpriseDashboard.subtitle')}
        actions={
          <Link
            to="/ai-workspace/chat"
            className="inline-flex items-center gap-1.5 rounded-lg border border-orange-500/40 bg-orange-500/15 px-3 py-2 text-xs font-semibold text-orange-100 transition hover:bg-orange-500/25"
          >
            {t('ai.askQuenyx')}
          </Link>
        }
      />

      <EnterpriseHealthBanner score={snapshot.enterpriseHealth.score} label={snapshot.enterpriseHealth.label} t={t} />

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <EnterpriseModuleCard title={t('enterpriseDashboard.infrastructure')} icon={<IconInfrastructure size={18} />} data={snapshot.infrastructure} labels={cardLabels} />
        <EnterpriseModuleCard title={t('enterpriseDashboard.assets')} icon={<IconAssets size={18} />} data={snapshot.assets} labels={cardLabels} />
        <EnterpriseModuleCard title={t('enterpriseDashboard.automation')} icon={<IconAutomation size={18} />} data={snapshot.automation} labels={cardLabels} />
        <EnterpriseModuleCard title={t('enterpriseDashboard.incidents')} icon={<IconIncidents size={18} />} data={snapshot.incidents} labels={cardLabels} />
        <EnterpriseModuleCard title={t('enterpriseDashboard.knowledge')} icon={<IconKnowledge size={18} />} data={snapshot.knowledge} labels={cardLabels} />
        <EnterpriseModuleCard title={t('enterpriseDashboard.support')} icon={<IconSupport size={18} />} data={snapshot.support} labels={cardLabels} />
        <EnterpriseModuleCard title={t('enterpriseDashboard.notifications')} icon={<IconNotifications size={18} />} data={snapshot.notifications} labels={cardLabels} />
        <EnterpriseModuleCard title={t('enterpriseDashboard.ai')} icon={<IconAi size={18} />} data={snapshot.ai} labels={cardLabels} />
        <EnterpriseModuleCard title={t('enterpriseDashboard.compliance')} icon={<IconCompliance size={18} />} data={snapshot.compliance} labels={cardLabels} />
        <EnterpriseModuleCard title={t('enterpriseDashboard.executive')} icon={<IconPlatform size={18} />} data={snapshot.executive} labels={cardLabels} />
        <EnterpriseModuleCard title={t('enterpriseDashboard.platformHealth')} icon={<IconPlatform size={18} />} data={snapshot.platformHealth} labels={cardLabels} />
      </div>
    </div>
  )
}
