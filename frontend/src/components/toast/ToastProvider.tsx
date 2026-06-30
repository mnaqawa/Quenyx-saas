import { createContext, useCallback, useContext, useMemo, useRef, useState, type ReactNode } from 'react'

export type ToastVariant = 'success' | 'error' | 'info' | 'warning'

export interface Toast {
  id: string
  message: string
  variant: ToastVariant
  duration: number
}

interface ToastContextValue {
  toasts: Toast[]
  notify: (message: string, variant?: ToastVariant, duration?: number) => string
  success: (message: string, duration?: number) => string
  error: (message: string, duration?: number) => string
  info: (message: string, duration?: number) => string
  warning: (message: string, duration?: number) => string
  dismiss: (id: string) => void
}

const ToastContext = createContext<ToastContextValue | null>(null)

const VARIANT_STYLES: Record<ToastVariant, string> = {
  success: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-100',
  error: 'border-rose-500/30 bg-rose-500/10 text-rose-100',
  info: 'border-sky-500/30 bg-sky-500/10 text-sky-100',
  warning: 'border-amber-500/30 bg-amber-500/10 text-amber-100',
}

/**
 * Lightweight, dependency-free toast system (GA remediation). Provides a global
 * `useToast()` hook for consistent async success/error feedback. Toasts are
 * accessible (role="status", aria-live polite) and auto-dismiss.
 */
export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([])
  const counter = useRef(0)

  const dismiss = useCallback((id: string) => {
    setToasts((current) => current.filter((t) => t.id !== id))
  }, [])

  const notify = useCallback(
    (message: string, variant: ToastVariant = 'info', duration = 4000): string => {
      counter.current += 1
      const id = `toast-${Date.now()}-${counter.current}`
      setToasts((current) => [...current, { id, message, variant, duration }])
      if (duration > 0) {
        window.setTimeout(() => dismiss(id), duration)
      }
      return id
    },
    [dismiss],
  )

  const value = useMemo<ToastContextValue>(
    () => ({
      toasts,
      notify,
      success: (m, d) => notify(m, 'success', d),
      error: (m, d) => notify(m, 'error', d ?? 6000),
      info: (m, d) => notify(m, 'info', d),
      warning: (m, d) => notify(m, 'warning', d),
      dismiss,
    }),
    [toasts, notify, dismiss],
  )

  return (
    <ToastContext.Provider value={value}>
      {children}
      <div
        className="pointer-events-none fixed bottom-4 end-4 z-[100] flex w-full max-w-sm flex-col gap-2"
        aria-live="polite"
        aria-atomic="false"
      >
        {toasts.map((toast) => (
          <div
            key={toast.id}
            role="status"
            className={[
              'pointer-events-auto flex items-start gap-3 rounded-lg border px-4 py-3 text-sm shadow-lg shadow-black/30 backdrop-blur',
              VARIANT_STYLES[toast.variant],
            ].join(' ')}
          >
            <span className="flex-1">{toast.message}</span>
            <button
              type="button"
              onClick={() => dismiss(toast.id)}
              className="shrink-0 rounded p-0.5 text-current/70 transition hover:text-current"
              aria-label="Dismiss notification"
            >
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                <line x1="18" y1="6" x2="6" y2="18" />
                <line x1="6" y1="6" x2="18" y2="18" />
              </svg>
            </button>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}

export function useToast(): ToastContextValue {
  const ctx = useContext(ToastContext)
  if (ctx === null) {
    throw new Error('useToast must be used within a ToastProvider')
  }
  return ctx
}

export default ToastProvider
