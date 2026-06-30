import { Link, useParams, useLocation } from 'react-router-dom'
import { useLanguage } from '../../i18n/LanguageContext'

/**
 * Sprint 25 — shared sub-navigation for the QynVA Enterprise Intelligence hub (Operator, Executive
 * Intelligence, Enterprise Analytics, Platform Health). Reuses the workspace route id from the URL.
 */
export function QynvaTabs() {
  const { t } = useLanguage()
  const { id } = useParams<{ id: string }>()
  const location = useLocation()

  const tabs = [
    { key: 'operator', label: t('qynva.tab.operator'), path: `/app/workspaces/${id}/qynva/operator` },
    { key: 'executive', label: t('qynva.tab.executive'), path: `/app/workspaces/${id}/qynva/executive` },
    { key: 'analytics', label: t('qynva.tab.analytics'), path: `/app/workspaces/${id}/qynva/analytics` },
    { key: 'health', label: t('qynva.tab.health'), path: `/app/workspaces/${id}/qynva/health` },
  ]

  return (
    <div className="flex flex-wrap gap-2 border-b border-white/10">
      {tabs.map((tab) => {
        const active = location.pathname === tab.path
        return (
          <Link
            key={tab.key}
            to={tab.path}
            className={`px-3 py-2 text-xs font-semibold transition ${
              active ? 'border-b-2 border-sky-400 text-white' : 'text-white/50 hover:text-white'
            }`}
          >
            {tab.label}
          </Link>
        )
      })}
    </div>
  )
}
