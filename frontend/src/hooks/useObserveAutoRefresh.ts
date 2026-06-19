import { useCallback, useEffect, useState } from 'react'

export type ObserveAutoRefreshInterval = '15' | '30' | '60' | '300' | 'off'

const STORAGE_KEY = 'qynsight_auto_refresh_interval'

function readStoredInterval(): ObserveAutoRefreshInterval {
  try {
    const v = localStorage.getItem(STORAGE_KEY)
    if (v === '15' || v === '30' || v === '60' || v === '300' || v === 'off') return v
  } catch {
    void 0
  }
  return '60'
}

export function useObserveAutoRefresh(onRefresh: () => void, enabled = true) {
  const [interval, setIntervalState] = useState<ObserveAutoRefreshInterval>(readStoredInterval)
  const [lastUpdatedAt, setLastUpdatedAt] = useState<Date | null>(null)
  const [, setAgeTick] = useState(0)

  const setInterval = useCallback((value: ObserveAutoRefreshInterval) => {
    setIntervalState(value)
    try {
      localStorage.setItem(STORAGE_KEY, value)
    } catch {
      void 0
    }
  }, [])

  const markUpdated = useCallback(() => {
    setLastUpdatedAt(new Date())
  }, [])

  const refreshNow = useCallback(() => {
    onRefresh()
  }, [onRefresh])

  useEffect(() => {
    if (!enabled || interval === 'off') return
    const ms = Number(interval) * 1000
    const id = window.setInterval(() => onRefresh(), ms)
    return () => window.clearInterval(id)
  }, [enabled, interval, onRefresh])

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
