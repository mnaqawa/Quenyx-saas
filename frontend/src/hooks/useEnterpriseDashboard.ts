import { useCallback, useEffect, useState } from 'react'
import { useObserveServices } from '../hooks/useObserveData'
import { qynvaService } from '../services/qynvaService'
import { incidentService } from '../services/incidentService'
import { automationService } from '../services/automationService'
import { assetIntelligenceService } from '../services/assetIntelligenceService'
import { knowledgeService } from '../services/knowledgeService'
import { supportService } from '../services/supportService'
import { notifyService } from '../services/notifyService'
import { getRequestErrorStatus } from '../lib/requestError'

export type ModuleCardStatus = 'loading' | 'locked' | 'not_configured' | 'no_data' | 'ready'

export interface ModuleCardData {
  status: ModuleCardStatus
  value?: string
  detail?: string
  href?: string
}

export interface EnterpriseHealth {
  score: number | null
  label: 'ready' | 'no_data' | 'loading'
}

export interface EnterpriseDashboardSnapshot {
  loading: boolean
  enterpriseHealth: EnterpriseHealth
  infrastructure: ModuleCardData
  assets: ModuleCardData
  automation: ModuleCardData
  incidents: ModuleCardData
  knowledge: ModuleCardData
  support: ModuleCardData
  notifications: ModuleCardData
  ai: ModuleCardData
  compliance: ModuleCardData
  executive: ModuleCardData
  platformHealth: ModuleCardData
}

function locked(): ModuleCardData {
  return { status: 'locked' }
}

function notConfigured(): ModuleCardData {
  return { status: 'not_configured' }
}

function noData(detail?: string): ModuleCardData {
  return { status: 'no_data', detail }
}

function ready(value: string, detail?: string, href?: string): ModuleCardData {
  return { status: 'ready', value, detail, href }
}

async function safeFetch<T>(fn: () => Promise<T>): Promise<T | null> {
  try {
    return await fn()
  } catch (err) {
    const status = getRequestErrorStatus(err)
    if (status === 403 || status === 404) return null
    return null
  }
}

export function useEnterpriseDashboard(
  workspaceId: string | null,
  workspaceUuid: string | undefined,
  allowedByKey: Record<string, boolean>,
) {
  const hasObserve = !!workspaceId && !!allowedByKey.qynsight
  const { data: observeData, loading: observeLoading } = useObserveServices({
    workspaceId: hasObserve ? workspaceId : null,
    limit: 500,
    realDataOnly: true,
  })

  const [snapshot, setSnapshot] = useState<EnterpriseDashboardSnapshot>(() => ({
    loading: true,
    enterpriseHealth: { score: null, label: 'loading' },
    infrastructure: { status: 'loading' },
    assets: { status: 'loading' },
    automation: { status: 'loading' },
    incidents: { status: 'loading' },
    knowledge: { status: 'loading' },
    support: { status: 'loading' },
    notifications: { status: 'loading' },
    ai: { status: 'loading' },
    compliance: { status: 'loading' },
    executive: { status: 'loading' },
    platformHealth: { status: 'loading' },
  }))

  const basePath = workspaceId ? `/app/workspaces/${workspaceId}` : ''

  const load = useCallback(async () => {
    if (!workspaceId || !workspaceUuid) {
      setSnapshot({
        loading: false,
        enterpriseHealth: { score: null, label: 'no_data' },
        infrastructure: notConfigured(),
        assets: notConfigured(),
        automation: notConfigured(),
        incidents: notConfigured(),
        knowledge: notConfigured(),
        support: notConfigured(),
        notifications: notConfigured(),
        ai: notConfigured(),
        compliance: notConfigured(),
        executive: notConfigured(),
        platformHealth: notConfigured(),
      })
      return
    }

    setSnapshot((prev) => ({
      ...prev,
      loading: true,
      enterpriseHealth: { score: null, label: 'loading' },
    }))

    const [
      executive,
      automation,
      assets,
      knowledge,
      incidents,
      tickets,
      notifications,
    ] = await Promise.all([
      allowedByKey.qynva
        ? safeFetch(() => qynvaService.executive(workspaceUuid))
        : Promise.resolve(null),
      allowedByKey.qynrun
        ? safeFetch(() => automationService.getOverview(workspaceUuid))
        : Promise.resolve(null),
      allowedByKey.qynasset
        ? safeFetch(() => assetIntelligenceService.getOverview(workspaceUuid))
        : Promise.resolve(null),
      allowedByKey.qynknow
        ? safeFetch(() => knowledgeService.getOverview(workspaceUuid))
        : Promise.resolve(null),
      allowedByKey.qynreact
        ? safeFetch(() => incidentService.list(workspaceUuid))
        : Promise.resolve(null),
      allowedByKey.qynsupport
        ? safeFetch(() => supportService.list(workspaceUuid))
        : Promise.resolve(null),
      allowedByKey.qynnotify
        ? safeFetch(() => notifyService.list(workspaceUuid, { status: 'unread' }))
        : Promise.resolve(null),
    ])

    const hostTotals = observeData?.hostTotals
    const serviceTotals = observeData?.serviceTotals
    const hostCount = hostTotals
      ? hostTotals.up + hostTotals.down + hostTotals.unreachable + hostTotals.pending
      : 0
    const problems = serviceTotals
      ? serviceTotals.warning + serviceTotals.critical + serviceTotals.unknown
      : 0

    let infrastructure: ModuleCardData = allowedByKey.qynsight ? noData() : locked()
    if (allowedByKey.qynsight) {
      if (hostCount > 0) {
        infrastructure = ready(
          String(hostCount),
          problems > 0 ? `${problems} service issues` : 'All checks healthy',
          `${basePath}/observe/overview`,
        )
      } else {
        infrastructure = noData()
      }
    }

    const buildModule = (
      allowed: boolean,
      data: unknown,
      mapReady: (d: NonNullable<typeof data>) => ModuleCardData,
    ): ModuleCardData => {
      if (!allowed) return locked()
      if (!data) return notConfigured()
      return mapReady(data as NonNullable<typeof data>)
    }

    const operationalScore = executive?.operational_health?.available
      ? (executive.operational_health.score as number | undefined)
      : undefined
    const infraScore = executive?.infrastructure_health?.available
      ? (executive.infrastructure_health.score as number | undefined)
      : undefined
    const scores = [operationalScore, infraScore].filter((s): s is number => typeof s === 'number' && !Number.isNaN(s))
    const avgScore = scores.length > 0 ? Math.round(scores.reduce((a, b) => a + b, 0) / scores.length) : null

    const enterpriseHealth: EnterpriseHealth =
      scores.length > 0
        ? { score: avgScore, label: 'ready' }
        : { score: null, label: 'no_data' }

    setSnapshot({
      loading: false,
      enterpriseHealth,
      infrastructure,
      assets: buildModule(!!allowedByKey.qynasset, assets, (d) => {
        const total = (d as { inventory_summary?: { total?: number } }).inventory_summary?.total ?? 0
        return total > 0 ? ready(String(total), undefined, `${basePath}/qynasset/intelligence`) : noData()
      }),
      automation: buildModule(!!allowedByKey.qynrun, automation, (d) => {
        const runs = (d as { counts?: { executions?: number } }).counts?.executions ?? 0
        return runs > 0 ? ready(String(runs), 'Executions', `${basePath}/qynrun`) : noData()
      }),
      incidents: buildModule(!!allowedByKey.qynreact, incidents, (d) => {
        const count = (d as { incidents?: unknown[] }).incidents?.length ?? 0
        return count > 0 ? ready(String(count), 'Open incidents', `${basePath}/qynreact`) : noData()
      }),
      knowledge: buildModule(!!allowedByKey.qynknow, knowledge, (d) => {
        const docs = (d as { document_count?: number }).document_count ?? 0
        return docs > 0 ? ready(String(docs), 'Documents', `${basePath}/qynknow/knowledge`) : noData()
      }),
      support: buildModule(!!allowedByKey.qynsupport, tickets, (d) => {
        const count = (d as { tickets?: unknown[] }).tickets?.length ?? 0
        return count > 0 ? ready(String(count), 'Tickets', `${basePath}/qynsupport`) : noData()
      }),
      notifications: buildModule(!!allowedByKey.qynnotify, notifications, (d) => {
        const count = (d as { notifications?: unknown[] }).notifications?.length ?? 0
        return count > 0 ? ready(String(count), 'Unread', `${basePath}/qynnotify`) : noData()
      }),
      ai: allowedByKey.qynva && executive?.ai_usage?.available
        ? ready(
            String((executive.ai_usage as { total_conversations?: number }).total_conversations ?? '—'),
            'Conversations',
            '/ai-workspace/overview',
          )
        : allowedByKey.qynva
          ? noData()
          : locked(),
      compliance: allowedByKey.qynshield
        ? notConfigured()
        : locked(),
      executive: buildModule(!!allowedByKey.qynva, executive, () =>
        avgScore !== null ? ready(`${avgScore}/100`, 'Enterprise score', `${basePath}/qynva/executive`) : noData(),
      ),
      platformHealth: buildModule(!!allowedByKey.qynva, executive, () => {
        const status = executive?.operational_health?.status
        return typeof status === 'string' ? ready(status, undefined, `${basePath}/qynva/health`) : noData()
      }),
    })
  }, [allowedByKey, basePath, observeData?.hostTotals, observeData?.serviceTotals, workspaceId, workspaceUuid])

  useEffect(() => {
    if (observeLoading && hasObserve) return
    void load()
  }, [load, observeLoading, hasObserve])

  return snapshot
}
