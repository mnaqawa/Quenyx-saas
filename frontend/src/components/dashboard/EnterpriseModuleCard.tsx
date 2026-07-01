import { Link } from 'react-router-dom'
import type { ReactNode } from 'react'
import type { ModuleCardData } from '../../hooks/useEnterpriseDashboard'

interface EnterpriseModuleCardProps {
  title: string
  icon: ReactNode
  data: ModuleCardData
  labels: {
    noData: string
    notConfigured: string
    locked: string
    loading: string
  }
}

function stateLabel(data: ModuleCardData, labels: EnterpriseModuleCardProps['labels']): string {
  switch (data.status) {
    case 'no_data':
      return labels.noData
    case 'not_configured':
      return labels.notConfigured
    case 'locked':
      return labels.locked
    case 'loading':
      return labels.loading
    default:
      return data.value ?? '—'
  }
}

export function EnterpriseModuleCard({ title, icon, data, labels }: EnterpriseModuleCardProps) {
  const value = data.status === 'ready' ? data.value : stateLabel(data, labels)
  const isMuted = data.status !== 'ready'

  const inner = (
    <div className="group flex h-full flex-col rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white transition hover:border-white/15 hover:bg-[#111a24]">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <p className="text-xs font-medium text-white/50">{title}</p>
          <p className={`mt-1 text-2xl font-semibold tabular-nums ${isMuted ? 'text-base font-medium text-white/55' : ''}`}>
            {value}
          </p>
          {data.detail ? <p className="mt-1 text-xs text-white/45">{data.detail}</p> : null}
        </div>
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-white/10 bg-white/[0.04] text-white/55 transition group-hover:border-white/15">
          {icon}
        </div>
      </div>
    </div>
  )

  if (data.status === 'ready' && data.href) {
    return (
      <Link to={data.href} className="block h-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-500">
        {inner}
      </Link>
    )
  }

  return inner
}
