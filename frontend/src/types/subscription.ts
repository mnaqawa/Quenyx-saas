export type PlanKey = 'free' | 'pro' | 'enterprise'

export interface PlanSummary {
  key: PlanKey
  name: string
}

export interface ProjectSubscription {
  status: 'active' | 'past_due' | 'canceled'
  plan: PlanSummary
  starts_at?: string | null
  ends_at?: string | null
}

export interface ProjectEntitlements {
  plan: PlanSummary
  modules_allowed: string[]
  limits: Record<string, any>
}
