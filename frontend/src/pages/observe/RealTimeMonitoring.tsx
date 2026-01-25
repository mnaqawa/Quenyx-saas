import { useState } from 'react'
import { useRealTimeMetrics, useSystemInfo, usePerformanceThresholds } from '../../hooks/useObserveData'
import { StatCard } from '../../components/observe/StatCard'
import { PageHeader } from '../../components/observe/PageHeader'

export default function RealTimeMonitoring() {
  const { metrics, loading: metricsLoading } = useRealTimeMetrics()
  const { info, loading: infoLoading } = useSystemInfo()
  const { thresholds } = usePerformanceThresholds()
  const [refreshInterval, setRefreshInterval] = useState('5 seconds')
  const [isLive, setIsLive] = useState(true)

  if (metricsLoading || infoLoading) {
    return <div className="text-sm text-white/60">Loading...</div>
  }

  if (!metrics || !info) {
    return <div className="text-sm text-white/60">No data available</div>
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Real-time Monitoring"
        subtitle="Live system metrics and performance indicators"
        actions={
          <>
            <select
              value={refreshInterval}
              onChange={(e) => setRefreshInterval(e.target.value)}
              className="rounded-lg border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white"
            >
              <option value="5 seconds" className="bg-slate-900 text-white">5 seconds</option>
              <option value="10 seconds" className="bg-slate-900 text-white">10 seconds</option>
              <option value="30 seconds" className="bg-slate-900 text-white">30 seconds</option>
            </select>
            <button
              onClick={() => setIsLive(!isLive)}
              className={`rounded-lg px-4 py-1.5 text-xs font-semibold transition ${
                isLive ? 'bg-rose-500 text-white' : 'border border-white/10 bg-white/5 text-white/70'
              }`}
            >
              {isLive ? '● Live' : 'Paused'}
            </button>
            <button className="rounded-lg border border-white/10 bg-white/5 px-4 py-1.5 text-xs text-white/70">
              Configure
            </button>
          </>
        }
      />

      <div className="grid gap-4 md:grid-cols-5">
        <StatCard
          title="CPU Usage"
          value={`${metrics.cpu.value}%`}
          detail={`${metrics.cpu.cores} • ${metrics.cpu.frequency}`}
          percentage={metrics.cpu.value}
        />
        <StatCard
          title="Memory"
          value={`${metrics.memory.value}%`}
          detail={`${metrics.memory.used} / ${metrics.memory.total}`}
          percentage={metrics.memory.value}
        />
        <StatCard
          title="Disk I/O"
          value={`${metrics.diskIO.value}%`}
          detail={`${metrics.diskIO.type} • ${metrics.diskIO.throughput}`}
          percentage={metrics.diskIO.value}
        />
        <StatCard
          title="Network"
          value={`${metrics.network.value}%`}
          detail={`${metrics.network.speed} • ${metrics.network.type}`}
          percentage={metrics.network.value}
        />
        <StatCard
          title="Temperature"
          value={`${metrics.temperature.value}°C`}
          detail={metrics.temperature.source}
          percentage={metrics.temperature.value}
        />
      </div>

      <div className="grid gap-4 md:grid-cols-2">
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <div className="mb-4 flex items-center justify-between">
            <div>
              <h3 className="text-sm font-semibold">System Performance</h3>
              <p className="text-xs text-white/60">Real-time CPU, Memory, and Disk usage</p>
            </div>
            <span className="rounded-full bg-emerald-500/20 px-2 py-1 text-[10px] font-medium text-emerald-200">
              LIVE
            </span>
          </div>
          <div className="h-64 rounded-lg bg-white/5 p-4">
            <div className="flex h-full items-center justify-center text-sm text-white/40">
              Chart placeholder - Performance over time
            </div>
          </div>
        </div>

        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <div className="mb-4 flex items-center justify-between">
            <div>
              <h3 className="text-sm font-semibold">Network Activity</h3>
              <p className="text-xs text-white/60">Real-time network utilization</p>
            </div>
            <span className="rounded-full bg-emerald-500/20 px-2 py-1 text-[10px] font-medium text-emerald-200">
              LIVE
            </span>
          </div>
          <div className="h-64 rounded-lg bg-white/5 p-4">
            <div className="flex h-full items-center justify-center text-sm text-white/40">
              Chart placeholder - Network activity
            </div>
          </div>
        </div>
      </div>

      <div className="grid gap-4 md:grid-cols-3">
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <h3 className="mb-4 text-sm font-semibold">System Information</h3>
          <div className="space-y-2 text-xs">
            <div className="flex justify-between">
              <span className="text-white/60">Hostname:</span>
              <span className="text-white">{info.hostname}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-white/60">OS:</span>
              <span className="text-white">{info.os}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-white/60">Kernel:</span>
              <span className="text-white">{info.kernel}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-white/60">Uptime:</span>
              <span className="text-white">{info.uptime}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-white/60">Load Average:</span>
              <span className="text-white">{info.loadAverage}</span>
            </div>
          </div>
        </div>

        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <h3 className="mb-4 text-sm font-semibold">Performance Thresholds</h3>
          <div className="space-y-3 text-xs">
            {thresholds.map((threshold) => (
              <div key={threshold.metric} className="space-y-1">
                <div className="flex justify-between">
                  <span className="text-white/60">{threshold.metric} Warning:</span>
                  <span className="text-white">{threshold.warning}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-white/60">{threshold.metric} Critical:</span>
                  <span className="text-rose-200">{threshold.critical}</span>
                </div>
              </div>
            ))}
          </div>
        </div>

        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
          <h3 className="mb-4 text-sm font-semibold">Quick Actions</h3>
          <div className="space-y-2">
            {['Performance Report', 'Resource Optimization', 'Alert Configuration', 'Export Metrics'].map(
              (action) => (
                <button
                  key={action}
                  className="flex w-full items-center gap-2 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-left text-xs text-white/70 transition hover:bg-white/10 hover:text-white"
                >
                  <span>{action}</span>
                </button>
              )
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
