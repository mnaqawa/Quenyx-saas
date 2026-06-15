import { Link, useNavigate } from 'react-router-dom'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'
import { useLanguage } from '../i18n/LanguageContext'
import { useOnboarding } from '../onboarding/OnboardingContext'
import { useProductTour } from '../tour/ProductTour'

export default function GettingStarted() {
  const { t } = useLanguage()
  const navigate = useNavigate()
  const { selectedWorkspaceId, workspaces } = useWorkspaceContext()
  const { markOnboarded } = useOnboarding()
  const { startTour } = useProductTour()

  const hasWorkspace = workspaces.length > 0

  const steps = [
    { title: t('getStarted.step1.title'), desc: t('getStarted.step1.desc') },
    { title: t('getStarted.step2.title'), desc: t('getStarted.step2.desc') },
    { title: t('getStarted.step3.title'), desc: t('getStarted.step3.desc') },
    { title: t('getStarted.step4.title'), desc: t('getStarted.step4.desc') },
    { title: t('getStarted.step5.title'), desc: t('getStarted.step5.desc') },
  ]

  const features = [
    { title: t('getStarted.feature1.title'), desc: t('getStarted.feature1.desc') },
    { title: t('getStarted.feature2.title'), desc: t('getStarted.feature2.desc') },
    { title: t('getStarted.feature3.title'), desc: t('getStarted.feature3.desc') },
    { title: t('getStarted.feature4.title'), desc: t('getStarted.feature4.desc') },
  ]

  const handleComplete = () => {
    markOnboarded()
    navigate('/dashboard')
  }

  return (
    <div className="mx-auto max-w-3xl space-y-8">
      {/* Hero */}
      <section className="overflow-hidden rounded-2xl border border-orange-500/20 bg-gradient-to-br from-orange-500/10 via-[#0f151d] to-[#0f151d] p-7 text-white">
        <span className="inline-flex items-center gap-2 rounded-full border border-orange-500/30 bg-orange-500/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-wider text-orange-200">
          {t('getStarted.badge')}
        </span>
        <h1 className="mt-4 text-3xl font-semibold tracking-tight">{t('getStarted.heroTitle')}</h1>
        <p className="mt-2 max-w-2xl text-sm leading-relaxed text-white/70">
          {t('getStarted.heroSubtitle')}
        </p>
        <div className="mt-6 flex flex-wrap items-center gap-3">
          <button
            type="button"
            onClick={startTour}
            className="inline-flex items-center gap-2 rounded-full bg-orange-500 px-5 py-2 text-sm font-semibold text-white transition hover:bg-orange-400"
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M12 3l1.5 4.5L18 9l-4.5 1.5L12 15l-1.5-4.5L6 9l4.5-1.5L12 3z" />
            </svg>
            {t('getStarted.startTour')}
          </button>
          {!hasWorkspace ? (
            <Link
              to="/app/workspaces"
              className="inline-flex items-center gap-2 rounded-full border border-white/15 px-5 py-2 text-sm font-semibold text-white/85 transition hover:bg-white/10"
            >
              {t('getStarted.createWorkspace')}
            </Link>
          ) : (
            <Link
              to="/dashboard"
              className="inline-flex items-center gap-2 rounded-full border border-white/15 px-5 py-2 text-sm font-semibold text-white/85 transition hover:bg-white/10"
            >
              {t('getStarted.goToDashboard')}
            </Link>
          )}
        </div>
      </section>

      {/* What is Quenyx / feature highlights */}
      <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-6 text-white">
        <h2 className="text-sm font-semibold uppercase tracking-wider text-white/70">
          {t('getStarted.featuresTitle')}
        </h2>
        <div className="mt-4 grid gap-3 sm:grid-cols-2">
          {features.map((feature) => (
            <div key={feature.title} className="rounded-xl border border-white/10 bg-white/5 p-4">
              <p className="text-sm font-semibold text-white">{feature.title}</p>
              <p className="mt-1 text-xs leading-relaxed text-white/60">{feature.desc}</p>
            </div>
          ))}
        </div>
      </section>

      {/* Setup steps */}
      <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-6 text-white">
        <h2 className="text-sm font-semibold uppercase tracking-wider text-white/70">
          {t('getStarted.stepsTitle')}
        </h2>
        <ol className="mt-4 space-y-4">
          {steps.map((step, index) => (
            <li key={step.title} className="flex gap-4">
              <span
                className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold ${
                  index === 0 ? 'bg-sky-500/30 text-sky-200' : 'bg-white/10 text-white/70'
                }`}
              >
                {index + 1}
              </span>
              <div>
                <p className="font-medium text-white">{step.title}</p>
                <p className="mt-1 text-xs text-white/60">{step.desc}</p>
              </div>
            </li>
          ))}
        </ol>
      </section>

      {/* Quick links */}
      <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-6 text-white">
        <h2 className="text-sm font-semibold uppercase tracking-wider text-white/70">
          {t('getStarted.quickLinks')}
        </h2>
        <div className="mt-4 flex flex-wrap gap-2 text-xs">
          <Link to="/app/workspaces" className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-white/80 transition hover:bg-white/10">
            {t('nav.projects')}
          </Link>
          {selectedWorkspaceId && (
            <Link
              to={`/app/workspaces/${selectedWorkspaceId}/observe/targets`}
              className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-white/80 transition hover:bg-white/10"
            >
              {t('getStarted.addHosts')}
            </Link>
          )}
          <Link to="/integrations" className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-white/80 transition hover:bg-white/10">
            {t('nav.integrations')}
          </Link>
          <Link to="/subscriptions" className="rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-white/80 transition hover:bg-white/10">
            {t('nav.subscriptions')}
          </Link>
        </div>
      </section>

      {/* Footer actions */}
      <div className="flex flex-wrap items-center gap-3 border-t border-white/10 pt-6">
        <button
          type="button"
          onClick={handleComplete}
          className="inline-flex items-center gap-2 rounded-full bg-sky-500 px-5 py-2 text-sm font-semibold text-white transition hover:bg-sky-400"
        >
          {t('getStarted.markComplete')}
        </button>
        <Link to="/dashboard" className="text-sm text-white/60 transition hover:text-white">
          {t('getStarted.skipForNow')}
        </Link>
      </div>
    </div>
  )
}
