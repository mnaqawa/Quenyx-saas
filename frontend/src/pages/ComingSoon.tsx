import { Link, useNavigate, useParams } from 'react-router-dom'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'
import { getModuleByKey } from '../constants/modules'

export default function ComingSoon() {
  const navigate = useNavigate()
  const { moduleKey } = useParams<{ moduleKey: string }>()
  const { selectedWorkspaceId, allowedByKey } = useWorkspaceContext()

  // Get module config if available
  const actualModuleKey = moduleKey || 'unknown'
  const moduleConfig = getModuleByKey(actualModuleKey)
  const displayName = moduleConfig?.displayName || actualModuleKey
  const moduleDescription = moduleConfig?.description || 'This module is under construction and will be available soon.'
  const isLocked = !allowedByKey[actualModuleKey]

  const canNavigateToObserve = selectedWorkspaceId !== null
  const observePath = selectedWorkspaceId ? `/app/workspaces/${selectedWorkspaceId}/observe/real-time-monitoring` : '#'

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-10 text-center text-white">
        {isLocked && (
          <div className="mb-6 inline-flex items-center gap-2 rounded-full border border-amber-500/30 bg-amber-500/10 px-4 py-2 text-sm text-amber-200">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
              <path d="M7 11V7a5 5 0 0 1 10 0v4" />
            </svg>
            <span>Locked</span>
          </div>
        )}

        <div className="mb-6">
          <h1 className="text-3xl font-semibold text-white mb-2">{displayName}</h1>
          <p className="text-sm text-white/60">{moduleDescription}</p>
        </div>

        {isLocked && (
          <div className="mb-6 rounded-lg border border-white/10 bg-white/5 p-4 text-left">
            <p className="text-sm text-white/70">
              You don't have access to this module yet. Contact your workspace administrator or upgrade your subscription to unlock this feature.
            </p>
          </div>
        )}

        <div className="flex flex-col sm:flex-row items-center justify-center gap-3">
          <button
            onClick={() => navigate('/app/workspaces')}
            className="rounded-full bg-sky-500 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-sky-400"
          >
            Back to Workspaces
          </button>
          <Link
            to={observePath}
            onClick={(e) => {
              if (!canNavigateToObserve) {
                e.preventDefault()
              }
            }}
            className={`rounded-full border border-white/20 px-6 py-2.5 text-sm font-semibold transition ${
              canNavigateToObserve
                ? 'bg-white/5 text-white hover:bg-white/10'
                : 'cursor-not-allowed opacity-50 text-white/50'
            }`}
            title={!canNavigateToObserve ? 'Select a workspace first' : undefined}
          >
            View QynSight
          </Link>
        </div>
      </div>

      {/* Additional info card */}
      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-6 text-white">
        <h2 className="mb-3 text-lg font-semibold">What's Coming?</h2>
        <p className="text-sm text-white/60">
          We're working hard to bring you this feature. Stay tuned for updates and announcements about when {displayName} will be available.
        </p>
      </div>
    </div>
  )
}
