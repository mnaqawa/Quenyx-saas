import { Component, type ErrorInfo, type ReactNode } from 'react'

interface ErrorBoundaryProps {
  children: ReactNode
  /** Optional custom fallback renderer. */
  fallback?: (error: Error, reset: () => void) => ReactNode
}

interface ErrorBoundaryState {
  error: Error | null
}

/**
 * Global error boundary (GA remediation). Catches uncaught render/runtime errors
 * anywhere in the React tree and shows a professional recovery screen instead of a
 * blank page. Keeps the rest of the app shell intact and lets the user retry or
 * reload. In development the error message is shown to aid debugging.
 */
export class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
  constructor(props: ErrorBoundaryProps) {
    super(props)
    this.state = { error: null }
  }

  static getDerivedStateFromError(error: Error): ErrorBoundaryState {
    return { error }
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    // Surface to the console for diagnostics; a real error-reporting sink (e.g.
    // Sentry) can be wired here without changing call sites.
    console.error('Unhandled UI error:', error, info.componentStack)
  }

  reset = (): void => {
    this.setState({ error: null })
  }

  render(): ReactNode {
    const { error } = this.state
    if (error === null) {
      return this.props.children
    }

    if (this.props.fallback) {
      return this.props.fallback(error, this.reset)
    }

    const isDev = Boolean(import.meta.env?.DEV)

    return (
      <div className="flex min-h-screen items-center justify-center bg-[#0b0f14] px-4 text-slate-100">
        <div className="w-full max-w-md rounded-2xl border border-white/10 bg-[#0f141b] p-8 text-center shadow-2xl shadow-black/40">
          <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-rose-500/15 text-rose-300">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
              <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
              <line x1="12" y1="9" x2="12" y2="13" />
              <line x1="12" y1="17" x2="12.01" y2="17" />
            </svg>
          </div>
          <h1 className="text-lg font-semibold text-white">Something went wrong</h1>
          <p className="mt-2 text-sm text-white/60">
            An unexpected error occurred while rendering this view. You can try again or reload the application.
          </p>
          {isDev ? (
            <pre className="mt-4 max-h-40 overflow-auto rounded-lg border border-white/10 bg-black/40 p-3 text-start text-xs text-rose-200">
              {error.message}
            </pre>
          ) : null}
          <div className="mt-6 flex items-center justify-center gap-3">
            <button
              type="button"
              onClick={this.reset}
              className="rounded-lg border border-white/15 bg-white/5 px-4 py-2 text-sm font-medium text-white transition hover:bg-white/10"
            >
              Try again
            </button>
            <button
              type="button"
              onClick={() => window.location.reload()}
              className="rounded-lg bg-orange-500/90 px-4 py-2 text-sm font-semibold text-white transition hover:bg-orange-500"
            >
              Reload
            </button>
          </div>
        </div>
      </div>
    )
  }
}

export default ErrorBoundary
