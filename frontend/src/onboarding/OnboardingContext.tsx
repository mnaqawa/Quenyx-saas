/* eslint-disable react-refresh/only-export-components -- hook colocated with provider */
import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
  type ReactNode,
} from 'react'

const STORAGE_KEY = 'quenyx.onboarded'

interface OnboardingContextValue {
  /** True once the user has completed onboarding (flag set, tour finished, or has workspaces). */
  isOnboarded: boolean
  /** Persist the onboarded flag and update state. */
  markOnboarded: () => void
  /** Clear the onboarded flag (mainly for testing / first-run simulation). */
  resetOnboarding: () => void
}

const OnboardingContext = createContext<OnboardingContextValue | undefined>(undefined)

function readFlag(): boolean {
  try {
    return localStorage.getItem(STORAGE_KEY) === 'true'
  } catch {
    return false
  }
}

export function OnboardingProvider({ children }: { children: ReactNode }) {
  const [isOnboarded, setIsOnboarded] = useState<boolean>(() => readFlag())

  const markOnboarded = useCallback(() => {
    try {
      localStorage.setItem(STORAGE_KEY, 'true')
    } catch {
      // Ignore storage failures; state still reflects onboarded for this session.
    }
    setIsOnboarded(true)
  }, [])

  const resetOnboarding = useCallback(() => {
    try {
      localStorage.removeItem(STORAGE_KEY)
    } catch {
      // Ignore storage failures.
    }
    setIsOnboarded(false)
  }, [])

  const value = useMemo<OnboardingContextValue>(
    () => ({ isOnboarded, markOnboarded, resetOnboarding }),
    [isOnboarded, markOnboarded, resetOnboarding],
  )

  return <OnboardingContext.Provider value={value}>{children}</OnboardingContext.Provider>
}

export function useOnboarding() {
  const context = useContext(OnboardingContext)
  if (!context) {
    throw new Error('useOnboarding must be used within OnboardingProvider')
  }
  return context
}
