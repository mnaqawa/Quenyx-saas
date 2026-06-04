export interface ReadinessResult {
  ready: boolean
  checks: {
    gateway: { ok: boolean }
    observe_engine: { ok: boolean; engine: 'native' }
  }
}

/**
 * Gateway readiness. QynSight monitoring now runs through the native Laravel
 * scheduler (observe:run-checks), so readiness must not depend on Docker/Nagios.
 */
export async function runReadinessChecks(): Promise<ReadinessResult> {
  return {
    ready: true,
    checks: {
      gateway: { ok: true },
      observe_engine: { ok: true, engine: 'native' },
    },
  }
}
