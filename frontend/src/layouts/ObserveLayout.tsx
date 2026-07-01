import { useEffect } from 'react'
import { Outlet, useLocation, useParams } from 'react-router-dom'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'
import { getRouteConfigFromPath } from '../constants/platformRegistry'
import { ObserveModuleUnavailable } from '../components/observe/ObserveModuleUnavailable'
import { useLanguage } from '../i18n/LanguageContext'
import { useObserveAccess } from '../hooks/useObserveAccess'
import { Breadcrumbs } from '../components/layout/Breadcrumbs'

export default function ObserveLayout() {
  const location = useLocation()
  const { id: routeWorkspaceId } = useParams<{ id: string }>()
  const { t } = useLanguage()
  const { selectedWorkspaceId, setSelectedWorkspaceId } = useWorkspaceContext()
  const { isModuleLocked } = useObserveAccess()

  // Keep context aligned with the workspace id in the URL so QynSight pages load the correct hosts/services.
  useEffect(() => {
    if (routeWorkspaceId && routeWorkspaceId !== selectedWorkspaceId) {
      setSelectedWorkspaceId(routeWorkspaceId)
    }
  }, [routeWorkspaceId, selectedWorkspaceId, setSelectedWorkspaceId])

  const workspaceId = routeWorkspaceId ?? selectedWorkspaceId

  if (!workspaceId) {
    return (
      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-10 text-center text-white">
        <p className="text-sm text-white/60">{t('observe.selectWorkspace')}</p>
      </div>
    )
  }

  if (isModuleLocked) {
    return (
      <div className="mx-auto max-w-7xl py-12" data-tour="tour-observe-content">
        <ObserveModuleUnavailable />
      </div>
    )
  }

  const routeConfig = getRouteConfigFromPath(location.pathname)
  const currentPageTitle = routeConfig?.i18nKey
    ? t(routeConfig.i18nKey)
    : routeConfig?.title ?? null

  return (
    <div className="mx-auto max-w-7xl space-y-6" data-tour="tour-observe-content">
      <Breadcrumbs
        className="mt-2"
        items={[
          { label: t('observe.breadcrumb.workspaces'), to: '/app/workspaces' },
          { label: t('observe.breadcrumb.qynsight'), to: `/app/workspaces/${workspaceId}/observe/overview` },
          ...(currentPageTitle && currentPageTitle !== 'QynSight' ? [{ label: currentPageTitle }] : []),
        ]}
      />
      <Outlet />
    </div>
  )
}
