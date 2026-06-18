import { Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'
import { getRouteConfigFromPath } from '../constants/platformRegistry'
import { ObserveModuleUnavailable } from '../components/observe/ObserveModuleUnavailable'
import { useLanguage } from '../i18n/LanguageContext'
import { useObserveAccess } from '../hooks/useObserveAccess'

export default function ObserveLayout() {
  const location = useLocation()
  const navigate = useNavigate()
  const { t } = useLanguage()
  const { selectedWorkspaceId } = useWorkspaceContext()
  const { isModuleLocked } = useObserveAccess()

  if (!selectedWorkspaceId) {
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
      <div className="flex items-center gap-1.5 text-xs text-white/40 mt-2">
        <button
          onClick={() => navigate('/app/workspaces')}
          className="hover:text-white/60 transition"
        >
          {t('observe.breadcrumb.workspaces')}
        </button>
        <span>/</span>
        <button
          onClick={() => navigate(`/app/workspaces/${selectedWorkspaceId}/observe/overview`)}
          className="hover:text-white/60 transition"
        >
          {t('observe.breadcrumb.qynsight')}
        </button>
        {currentPageTitle && currentPageTitle !== 'QynSight' && (
          <>
            <span>/</span>
            <span className="text-white/50">{currentPageTitle}</span>
          </>
        )}
      </div>
      <Outlet />
    </div>
  )
}
