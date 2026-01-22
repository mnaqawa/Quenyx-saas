import { useEffect, useState } from 'react'
import { useProjectContext } from '../projects/ProjectContext'
import { projectMembershipService, ProjectMembership, ProjectInvite } from '../services/projectMembershipService'
import { authService } from '../services/authService'

type ProjectRole = 'owner' | 'admin' | 'member' | 'viewer'

function ProjectMembers() {
  const { selectedProjectId } = useProjectContext()
  const [memberships, setMemberships] = useState<ProjectMembership[]>([])
  const [invites, setInvites] = useState<ProjectInvite[]>([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [addingMember, setAddingMember] = useState(false)
  const [invitingMember, setInvitingMember] = useState(false)
  const [newMemberEmail, setNewMemberEmail] = useState('')
  const [newMemberRole, setNewMemberRole] = useState<'admin' | 'member' | 'viewer'>('member')
  const [currentUserId, setCurrentUserId] = useState<number | null>(null)
  const [currentUserRole, setCurrentUserRole] = useState<ProjectRole | null>(null)
  const [unauthorized, setUnauthorized] = useState(false)

  useEffect(() => {
    const fetchCurrentUser = async () => {
      try {
        const user = await authService.me()
        setCurrentUserId(user.id)
      } catch (err) {
        // Ignore
      }
    }
    fetchCurrentUser()
  }, [])

  useEffect(() => {
    if (selectedProjectId && currentUserId !== null) {
      loadMemberships()
    } else {
      setMemberships([])
      setInvites([])
      setCurrentUserRole(null)
      setUnauthorized(false)
    }
  }, [selectedProjectId, currentUserId])

  const loadMemberships = async () => {
    if (!selectedProjectId || currentUserId === null) return

    setLoading(true)
    setError(null)
    setUnauthorized(false)
    try {
      const data = await projectMembershipService.getProjectMemberships(selectedProjectId)
      setMemberships(data.memberships || [])
      setInvites(data.invites || [])
      
      // Derive current user's role from memberships list by comparing user.id
      const userMembership = data.memberships.find((m) => m.user.id === currentUserId || m.user_id === currentUserId)
      if (userMembership) {
        setCurrentUserRole(userMembership.role)
      } else {
        // Check if user is the owner (owner doesn't have a membership record, but appears in list)
        const isOwner = data.memberships.some((m) => m.role === 'owner' && (m.user.id === currentUserId || m.user_id === currentUserId))
        setCurrentUserRole(isOwner ? 'owner' : null)
      }
    } catch (err) {
      // Handle 403 unauthorized errors gracefully
      if (err instanceof Error && (err as any).status === 403) {
        setUnauthorized(true)
        setError('You do not have permission to view memberships for this project.')
      } else {
        setError(err instanceof Error ? err.message : 'Failed to load memberships')
      }
    } finally {
      setLoading(false)
    }
  }

  const handleAddMember = async () => {
    if (!selectedProjectId || !newMemberEmail.trim()) return

    setError(null)
    try {
      await projectMembershipService.addMember(selectedProjectId, newMemberEmail.trim(), newMemberRole)
      setNewMemberEmail('')
      setNewMemberRole('member')
      setAddingMember(false)
      await loadMemberships()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to add member')
    }
  }

  const handleInvite = async () => {
    if (!selectedProjectId || !newMemberEmail.trim()) return

    setError(null)
    try {
      await projectMembershipService.createInvite(selectedProjectId, newMemberEmail.trim(), newMemberRole)
      setNewMemberEmail('')
      setNewMemberRole('member')
      setInvitingMember(false)
      await loadMemberships()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create invite')
    }
  }

  const handleUpdateRole = async (membershipId: number, newRole: ProjectRole) => {
    if (!selectedProjectId || !membershipId) return

    setError(null)
    try {
      await projectMembershipService.updateMembershipRole(selectedProjectId, membershipId, newRole)
      await loadMemberships()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update role')
    }
  }

  const handleRemoveMembership = async (membershipId: number) => {
    if (!selectedProjectId || !membershipId) return
    if (!confirm('Are you sure you want to remove this member?')) return

    setError(null)
    try {
      await projectMembershipService.removeMembership(selectedProjectId, membershipId)
      await loadMemberships()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to remove member')
    }
  }

  const canManage = currentUserRole === 'owner' || currentUserRole === 'admin'
  const isOwner = currentUserRole === 'owner'

  if (!selectedProjectId) {
    return (
      <div className="space-y-6">
        <div className="space-y-1 text-center">
          <h1 className="text-2xl font-semibold text-white">Project Members</h1>
          <p className="text-sm text-white/60">Manage team members and their roles</p>
        </div>
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-10 text-center text-white">
          <p className="text-sm text-white/60">Select a project to manage members</p>
        </div>
      </div>
    )
  }

  if (loading) {
    return <div className="text-sm text-white/60">Loading members...</div>
  }

  // Show unauthorized message if user doesn't have permission
  if (unauthorized) {
    return (
      <div className="space-y-6">
        <div className="space-y-1 text-center">
          <h1 className="text-2xl font-semibold text-white">Project Members</h1>
          <p className="text-sm text-white/60">Manage team members and their roles</p>
        </div>
        <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
          {error || 'You do not have permission to view memberships for this project.'}
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="space-y-1 text-center">
        <h1 className="text-2xl font-semibold text-white">Project Members</h1>
        <p className="text-sm text-white/60">Manage team members and their roles</p>
      </div>

      {error && (
        <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
          {error}
        </div>
      )}

      {!canManage && (
        <div className="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
          Only project owners and admins can manage members
        </div>
      )}

      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
        {canManage && (
          <div className="mb-4 space-y-3">
            {!addingMember && !invitingMember ? (
              <div className="flex gap-2">
                <button
                  type="button"
                  onClick={() => setAddingMember(true)}
                  className="rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-400"
                >
                  + Add Member
                </button>
                <button
                  type="button"
                  onClick={() => setInvitingMember(true)}
                  className="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-white/70 transition hover:bg-white/10"
                >
                  + Invite
                </button>
              </div>
            ) : (
              <div className="space-y-3 rounded-lg border border-white/10 bg-white/5 p-4">
                <div>
                  <label className="mb-1 block text-xs text-white/60">Email</label>
                  <input
                    type="email"
                    value={newMemberEmail}
                    onChange={(e) => setNewMemberEmail(e.target.value)}
                    className="w-full rounded-md border border-white/10 bg-white/5 px-3 py-2 text-sm text-white focus:border-sky-500 focus:outline-none"
                    placeholder="user@example.com"
                  />
                </div>
                <div>
                  <label className="mb-1 block text-xs text-white/60">Role</label>
                  <select
                    value={newMemberRole}
                    onChange={(e) => setNewMemberRole(e.target.value as 'admin' | 'member' | 'viewer')}
                    className="w-full rounded-md border border-white/10 bg-white/5 px-3 py-2 text-sm text-white focus:border-sky-500 focus:outline-none"
                  >
                    <option value="admin">Admin</option>
                    <option value="member">Member</option>
                    <option value="viewer">Viewer</option>
                  </select>
                </div>
                <div className="flex gap-2">
                  <button
                    type="button"
                    onClick={addingMember ? handleAddMember : handleInvite}
                    className="rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-400"
                  >
                    {addingMember ? 'Add' : 'Send Invite'}
                  </button>
                  <button
                    type="button"
                    onClick={() => {
                      setAddingMember(false)
                      setInvitingMember(false)
                      setNewMemberEmail('')
                      setNewMemberRole('member')
                    }}
                    className="rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-white/70 transition hover:bg-white/10"
                  >
                    Cancel
                  </button>
                </div>
              </div>
            )}
          </div>
        )}

        <div className="space-y-4">
          <div>
            <h3 className="mb-3 text-sm font-semibold text-white/80">Members</h3>
            <div className="space-y-3">
              {memberships.length === 0 ? (
                <div className="text-sm text-white/60">No members found</div>
              ) : (
                memberships.map((membership) => (
                  <div
                    key={membership.id || `owner-${membership.user_id}`}
                    className="flex items-center justify-between rounded-lg border border-white/5 bg-white/5 p-4"
                  >
                    <div>
                      <p className="font-semibold">{membership.user.name}</p>
                      <p className="text-xs text-white/60">{membership.user.email}</p>
                    </div>
                    <div className="flex items-center gap-3">
                      {canManage && membership.role !== 'owner' && membership.id ? (
                        <>
                          <select
                            value={membership.role}
                            onChange={(e) =>
                              handleUpdateRole(membership.id!, e.target.value as ProjectRole)
                            }
                            className="rounded-md border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white focus:border-sky-500 focus:outline-none"
                          >
                            {/* Only show owner option if current user is owner */}
                            {isOwner && <option value="owner">Owner</option>}
                            <option value="admin">Admin</option>
                            <option value="member">Member</option>
                            <option value="viewer">Viewer</option>
                          </select>
                          <button
                            type="button"
                            onClick={() => membership.id && handleRemoveMembership(membership.id)}
                            className="rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-1.5 text-xs text-rose-200 transition hover:bg-rose-500/20"
                          >
                            Remove
                          </button>
                        </>
                      ) : (
                        <span className="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs text-white/70">
                          {membership.role.charAt(0).toUpperCase() + membership.role.slice(1)}
                        </span>
                      )}
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>

          {invites.length > 0 && (
            <div>
              <h3 className="mb-3 text-sm font-semibold text-white/80">Pending Invites</h3>
              <div className="space-y-3">
                {invites.map((invite) => (
                  <div
                    key={invite.id}
                    className="flex items-center justify-between rounded-lg border border-white/5 bg-white/5 p-4"
                  >
                    <div className="flex-1">
                      <p className="font-semibold">{invite.email}</p>
                      <p className="text-xs text-white/60">
                        Invited by {invite.invited_by.name} • {invite.status}
                      </p>
                      {canManage && invite.token && (
                        <div className="mt-2 flex items-center gap-2">
                          <code className="rounded bg-white/5 px-2 py-1 text-[10px] text-white/80 font-mono">
                            {invite.token}
                          </code>
                          <button
                            type="button"
                            onClick={() => {
                              navigator.clipboard.writeText(invite.token!)
                              // Could show a toast here, but keeping minimal
                            }}
                            className="rounded-md border border-white/10 bg-white/5 px-2 py-1 text-[10px] text-white/70 transition hover:bg-white/10"
                          >
                            Copy Token
                          </button>
                        </div>
                      )}
                    </div>
                    <span className="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs text-white/70">
                      {invite.role.charAt(0).toUpperCase() + invite.role.slice(1)}
                    </span>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

export default ProjectMembers
