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
