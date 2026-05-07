/**
 * Enriched errors thrown from apiClient (and compatible fetches) for status / validation.
 */
export type RequestError = Error & {
  status?: number
  url?: string
  isAuthError?: boolean
  userMessage?: string
  errors?: unknown
  isEntitlementLock?: boolean
  originalError?: Error
}

export function isRequestError(err: unknown): err is RequestError {
  return err instanceof Error
}

export function getRequestErrorStatus(err: unknown): number | undefined {
  if (err instanceof Error && 'status' in err) {
    const s = (err as RequestError).status
    return typeof s === 'number' ? s : undefined
  }
  return undefined
}

export function getRequestErrorFieldErrors(
  err: unknown
): Record<string, string | string[] | undefined> | null {
  if (err instanceof Error && 'errors' in err) {
    const o = (err as RequestError).errors
    if (o && typeof o === 'object' && !Array.isArray(o)) {
      return o as Record<string, string | string[] | undefined>
    }
  }
  return null
}

export function enrichError(err: Error, meta: Partial<Omit<RequestError, keyof Error>>): RequestError {
  return Object.assign(err, meta) as RequestError
}
