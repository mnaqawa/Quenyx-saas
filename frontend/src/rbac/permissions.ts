// Canonical RBAC permission helpers
// Reuse Role type from workspace types
export type Role = 'owner' | 'admin' | 'member' | 'viewer'

/**
 * Check if a role can manage members (invite, add, update, remove)
 * Rules: owner and admin can manage members
 */
export function canManageMembers(role: Role | null): boolean {
  return role === 'owner' || role === 'admin'
}

/**
 * Check if a role can manage integrations
 * Rules: owner and admin can manage integrations (same as members per spec)
 */
export function canManageIntegrations(role: Role | null): boolean {
  return role === 'owner' || role === 'admin'
}

/**
 * Check if a role can promote someone to owner
 * Rules: only owner can promote to owner
 */
export function canPromoteToOwner(role: Role | null): boolean {
  return role === 'owner'
}

/** Edit hosts, monitoring profile, alert rules (owner/admin). */
export function canEditObserveConfig(role: Role | null): boolean {
  return role === 'owner' || role === 'admin'
}

/** Run checks, port scans, and other operational workflows (not viewers). */
export function canRunObserveOperations(role: Role | null): boolean {
  return role === 'owner' || role === 'admin' || role === 'member'
}

/** Acknowledge alert events (owner/admin/member). */
export function canAcknowledgeAlerts(role: Role | null): boolean {
  return role === 'owner' || role === 'admin' || role === 'member'
}

/** Create, edit, delete, toggle alert rules (owner/admin). */
export function canManageAlertRules(role: Role | null): boolean {
  return role === 'owner' || role === 'admin'
}
