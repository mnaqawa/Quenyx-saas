/* eslint-disable react-refresh/only-export-components -- hook colocated with provider */
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import { createPortal } from 'react-dom'
import { useLocation, useNavigate } from 'react-router-dom'
import { useLanguage } from '../i18n/LanguageContext'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'
import { useOnboarding } from '../onboarding/OnboardingContext'
import {
  TOUR_STORAGE_COMPLETED,
  TOUR_STORAGE_SKIPPED,
  tourSteps,
  type TourPlacement,
  type TourStep,
} from './tourSteps'

interface TargetRect {
  top: number
  left: number
  width: number
  height: number
}

interface ProductTourContextValue {
  isActive: boolean
  currentStep: number
  totalSteps: number
  startTour: () => void
  skipTour: () => void
  nextStep: () => void
  prevStep: () => void
}

const ProductTourContext = createContext<ProductTourContextValue | undefined>(undefined)

const PADDING = 8
const POPOVER_WIDTH = 360

function resolveRoute(step: TourStep, workspaceId: string | null): string | undefined {
  if (!step.route) return undefined
  return typeof step.route === 'function' ? step.route(workspaceId) : step.route
}

function getTargetElement(target?: string): HTMLElement | null {
  if (!target) return null
  return document.querySelector(`[data-tour="${target}"]`) as HTMLElement | null
}

function measureTarget(el: HTMLElement | null): TargetRect | null {
  if (!el) return null
  const rect = el.getBoundingClientRect()
  return {
    top: rect.top,
    left: rect.left,
    width: rect.width,
    height: rect.height,
  }
}

function computePopoverPosition(
  rect: TargetRect | null,
  placement: TourPlacement,
): { top: number; left: number } {
  if (!rect || placement === 'center') {
    return {
      top: Math.max(24, window.innerHeight / 2 - 160),
      left: Math.max(16, window.innerWidth / 2 - POPOVER_WIDTH / 2),
    }
  }

  const gap = 16
  let top = rect.top
  let left = rect.left

  switch (placement) {
    case 'bottom':
      top = rect.top + rect.height + gap
      left = rect.left + rect.width / 2 - POPOVER_WIDTH / 2
      break
    case 'top':
      top = rect.top - gap - 220
      left = rect.left + rect.width / 2 - POPOVER_WIDTH / 2
      break
    case 'left':
      top = rect.top + rect.height / 2 - 110
      left = rect.left - gap - POPOVER_WIDTH
      break
    case 'right':
      top = rect.top + 12
      left = rect.left + rect.width + gap
      break
    default:
      break
  }

  const maxLeft = window.innerWidth - POPOVER_WIDTH - 16
  const maxTop = window.innerHeight - 280
  return {
    top: Math.max(16, Math.min(top, maxTop)),
    left: Math.max(16, Math.min(left, maxLeft)),
  }
}

function TourIcon() {
  return (
    <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-orange-500/20 text-orange-400">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
        <path d="M12 3l1.5 4.5L18 9l-4.5 1.5L12 15l-1.5-4.5L6 9l4.5-1.5L12 3z" />
        <path d="M5 19l1 2 2 1-2 1-1 2-1-2-2-1 2-1 1-2z" />
      </svg>
    </span>
  )
}

function ProductTourOverlay({
  stepIndex,
  onSkip,
  onBack,
  onNext,
}: {
  stepIndex: number
  onSkip: () => void
  onBack: () => void
  onNext: () => void
}) {
  const { t } = useLanguage()
  const step = tourSteps[stepIndex]
  const placement = step.placement ?? 'center'
  const [targetRect, setTargetRect] = useState<TargetRect | null>(null)
  const isLast = stepIndex === tourSteps.length - 1
  const isFirst = stepIndex === 0

  const updateRect = useCallback(() => {
    const el = getTargetElement(step.target)
    if (el && placement !== 'center') {
      el.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' })
    }
    setTargetRect(measureTarget(el))
  }, [step.target, placement])

  useEffect(() => {
    updateRect()
    const timer = window.setTimeout(updateRect, 350)
    window.addEventListener('resize', updateRect)
    window.addEventListener('scroll', updateRect, true)
    return () => {
      window.clearTimeout(timer)
      window.removeEventListener('resize', updateRect)
      window.removeEventListener('scroll', updateRect, true)
    }
  }, [updateRect, stepIndex])

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'ArrowRight' || event.key === 'Enter') {
        event.preventDefault()
        onNext()
      } else if (event.key === 'ArrowLeft') {
        event.preventDefault()
        onBack()
      } else if (event.key === 'Escape') {
        event.preventDefault()
        onSkip()
      }
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [onBack, onNext, onSkip])

  const popoverPos = computePopoverPosition(targetRect, placement)
  const showSpotlight = targetRect && placement !== 'center'

  return createPortal(
    <div className="fixed inset-0 z-[100]" aria-live="polite">
      {!showSpotlight ? (
        <div className="absolute inset-0 bg-black/65 backdrop-blur-[1px]" aria-hidden />
      ) : null}

      {showSpotlight && targetRect ? (
        <div
          className="pointer-events-none absolute rounded-xl ring-2 ring-orange-500/80 transition-all duration-300"
          style={{
            top: targetRect.top - PADDING,
            left: targetRect.left - PADDING,
            width: targetRect.width + PADDING * 2,
            height: targetRect.height + PADDING * 2,
            boxShadow: '0 0 0 9999px rgba(0,0,0,0.65)',
          }}
        />
      ) : null}

      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="product-tour-title"
        className="absolute z-[101] w-[min(360px,calc(100vw-32px))] rounded-2xl border border-white/10 bg-[#141a22] p-5 shadow-2xl shadow-black/50 transition-all duration-300"
        style={{ top: popoverPos.top, left: popoverPos.left }}
      >
        <div className="flex items-start justify-between gap-3">
          <div className="flex items-center gap-2">
            <TourIcon />
            <span className="text-[11px] font-semibold uppercase tracking-[0.15em] text-white/45">
              {t('tour.label')} · {stepIndex + 1} / {tourSteps.length}
            </span>
          </div>
          <button
            type="button"
            onClick={onSkip}
            className="rounded-md p-1 text-white/40 transition hover:bg-white/10 hover:text-white"
            aria-label={t('tour.close')}
          >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M18 6L6 18M6 6l12 12" />
            </svg>
          </button>
        </div>

        <h2 id="product-tour-title" className="mt-4 text-lg font-semibold text-white">
          {t(step.titleKey)}
        </h2>
        <p className="mt-2 text-sm leading-relaxed text-white/65">{t(step.bodyKey)}</p>

        <div className="mt-5 flex gap-1">
          {tourSteps.map((_, index) => (
            <span
              key={index}
              className={`h-1 flex-1 rounded-full transition-colors ${
                index === stepIndex ? 'bg-orange-500' : 'bg-white/10'
              }`}
            />
          ))}
        </div>

        <div className="mt-5 flex items-center justify-between gap-3">
          <button
            type="button"
            onClick={onSkip}
            className="text-xs font-medium text-white/45 transition hover:text-white/70"
          >
            {t('tour.skip')}
          </button>
          <div className="flex items-center gap-2">
            {!isFirst ? (
              <button
                type="button"
                onClick={onBack}
                className="inline-flex items-center gap-1 rounded-lg border border-white/10 px-3 py-1.5 text-xs font-semibold text-white/75 transition hover:bg-white/10"
              >
                ← {t('tour.back')}
              </button>
            ) : null}
            <button
              type="button"
              onClick={onNext}
              className="inline-flex items-center gap-1 rounded-lg bg-orange-500 px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-orange-400"
            >
              {isLast ? `${t('tour.done')} →` : `${t('tour.next')} →`}
            </button>
          </div>
        </div>
      </div>
    </div>,
    document.body,
  )
}

export function ProductTourProvider({ children }: { children: ReactNode }) {
  const navigate = useNavigate()
  const location = useLocation()
  const { selectedWorkspaceId } = useWorkspaceContext()
  const { markOnboarded } = useOnboarding()
  const [isActive, setIsActive] = useState(false)
  const [currentStep, setCurrentStep] = useState(0)
  const autoStarted = useRef(false)

  const completeTour = useCallback(() => {
    localStorage.setItem(TOUR_STORAGE_COMPLETED, 'true')
    localStorage.removeItem(TOUR_STORAGE_SKIPPED)
    markOnboarded()
    setIsActive(false)
    setCurrentStep(0)
  }, [markOnboarded])

  const skipTour = useCallback(() => {
    localStorage.setItem(TOUR_STORAGE_SKIPPED, 'true')
    markOnboarded()
    setIsActive(false)
    setCurrentStep(0)
  }, [markOnboarded])

  const startTour = useCallback(() => {
    setCurrentStep(0)
    setIsActive(true)
    navigate('/dashboard')
  }, [navigate])

  const goToStep = useCallback(
    (index: number) => {
      const step = tourSteps[index]
      const route = resolveRoute(step, selectedWorkspaceId)
      if (route && route !== location.pathname) {
        navigate(route)
      }
      setCurrentStep(index)
    },
    [location.pathname, navigate, selectedWorkspaceId],
  )

  const nextStep = useCallback(() => {
    if (currentStep >= tourSteps.length - 1) {
      completeTour()
      return
    }
    goToStep(currentStep + 1)
  }, [completeTour, currentStep, goToStep])

  const prevStep = useCallback(() => {
    if (currentStep <= 0) return
    goToStep(currentStep - 1)
  }, [currentStep, goToStep])

  // Auto-start for new users who have not completed or skipped the tour
  useEffect(() => {
    if (autoStarted.current) return
    const completed = localStorage.getItem(TOUR_STORAGE_COMPLETED) === 'true'
    const skipped = localStorage.getItem(TOUR_STORAGE_SKIPPED) === 'true'
    if (completed || skipped) return

    autoStarted.current = true
    const timer = window.setTimeout(() => {
      setCurrentStep(0)
      setIsActive(true)
      navigate('/dashboard')
    }, 800)
    return () => window.clearTimeout(timer)
  }, [navigate])

  const value = useMemo<ProductTourContextValue>(
    () => ({
      isActive,
      currentStep,
      totalSteps: tourSteps.length,
      startTour,
      skipTour,
      nextStep,
      prevStep,
    }),
    [currentStep, isActive, nextStep, prevStep, skipTour, startTour],
  )

  return (
    <ProductTourContext.Provider value={value}>
      {children}
      {isActive ? (
        <ProductTourOverlay
          stepIndex={currentStep}
          onSkip={skipTour}
          onBack={prevStep}
          onNext={nextStep}
        />
      ) : null}
    </ProductTourContext.Provider>
  )
}

export function useProductTour() {
  const context = useContext(ProductTourContext)
  if (!context) {
    throw new Error('useProductTour must be used within ProductTourProvider')
  }
  return context
}
