import { useCallback, useEffect, useState, useRef } from 'react'

export type ObserveAutoRefreshInterval = '15' | '30' | '60' | '300' | 'off'

const STORAGE_KEY = 'qynsight_auto_refresh_interval'

interface UseObserveAutoRefreshOptions {
  defaultInterval?: ObserveAutoRefreshInterval
  storageKey?: string
}

function readStoredInterval(
  storageKey: string,
  fallback: ObserveAutoRefreshInterval,
): ObserveAutoRefreshInterval {
  try {
    const v = localStorage.getItem(storageKey)
    if (v === '15' || v === '30' || v === '60' || v === '300' || v === 'off') return v
  } catch {
    void 0
  }
  return fallback
}

export function useObserveAutoRefresh(
  onRefresh: () => void,
  enabled = true,
  options?: UseObserveAutoRefreshOptions,
) {
  const storageKey = options?.storageKey ?? STORAGE_KEY
  const fallback = options?.defaultInterval ?? '60'
  const [interval, setIntervalState] = useState<ObserveAutoRefreshInterval>(() =>
    readStoredInterval(storageKey, fallback),
  )
  const [lastUpdatedAt, setLastUpdatedAt] = useState<Date | null>(null)
  const [, setAgeTick] = useState(0)

  const setInterval = useCallback(
    (value: ObserveAutoRefreshInterval) => {
      setIntervalState(value)
      try {
        localStorage.setItem(storageKey, value)
      } catch {
        void 0
      }
    },
    [storageKey],
  )

  const markUpdated = useCallback(() => {
    setLastUpdatedAt(new Date())
  }, [])

  const refreshNow = useCallback(() => {
    onRefresh()
  }, [onRefresh])

  const onRefreshRef = useRef(onRefresh)
  onRefreshRef.current = onRefresh

  useEffect(() => {
    if (!enabled || interval === 'off') return
    const ms = Number(interval) * 1000
    const id = window.setInterval(() => onRefreshRef.current(), ms)
    return () => window.clearInterval(id)
  }, [enabled, interval])

  useEffect(() => {
    const id = window.setInterval(() => setAgeTick((n) => n + 1), 1000)
    return () => window.clearInterval(id)
  }, [])

  const secondsAgo =
    lastUpdatedAt == null
      ? null
      : Math.max(0, Math.floor((Date.now() - lastUpdatedAt.getTime()) / 1000))

  return {
    interval,
    setInterval,
    lastUpdatedAt,
    markUpdated,
    refreshNow,
    secondsAgo,
  }
}
