import { useEffect, useState } from 'react'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'
import { workspaceMembershipService, WorkspaceMembership, WorkspaceInvite } from '../services/workspaceMembershipService'
import { authService } from '../services/authService'
import { Role, canManageMembers, canPromoteToOwner } from '../rbac/permissions'

function WorkspaceMembers() {
  const { selectedWorkspaceId } = useWorkspaceContext()
  const [memberships, setMemberships] = useState<WorkspaceMembership[]>([])
  const [invites, setInvites] = useState<WorkspaceInvite[]>([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [addingMember, setAddingMember] = useState(false)
  const [invitingMember, setInvitingMember] = useState(false)
  const [newMemberEmail, setNewMemberEmail] = useState('')
  const [newMemberRole, setNewMemberRole] = useState<'admin' | 'member' | 'viewer'>('member')
  const [currentUserId, setCurrentUserId] = useState<number | null>(null)
  const [currentUserRole, setCurrentUserRole] = useState<Role | null>(null)
  const [unauthorized, setUnauthorized] = useState(false)
  const [dragMembershipId, setDragMembershipId] = useState<number | null>(null)
  const [dragTargetRole, setDragTargetRole] = useState<Role | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

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
    if (selectedWorkspaceId && currentUserId !== null) {
      loadMemberships()
    } else {
      setMemberships([])
      setInvites([])
      setCurrentUserRole(null)
      setUnauthorized(false)
    }
  }, [selectedWorkspaceId, currentUserId])

  useEffect(() => {
    if (!notice) return
    const timer = window.setTimeout(() => setNotice(null), 2500)
    return () => window.clearTimeout(timer)
  }, [notice])

  const loadMemberships = async () => {
    if (!selectedWorkspaceId || currentUserId === null) return

    setLoading(true)
    setError(null)
    setNotice(null)
    setUnauthorized(false)
    try {
      const data = await workspaceMembershipService.getWorkspaceMemberships(Number(selectedWorkspaceId))
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
        setError('You do not have permission to view memberships for this workspace.')
      } else {
        setError(err instanceof Error ? err.message : 'Failed to load memberships')
      }
    } finally {
      setLoading(false)
    }
  }

  const handleAddMember = async () => {
    if (!selectedWorkspaceId || !newMemberEmail.trim()) return

    setError(null)
    try {
      await workspaceMembershipService.addMember(Number(selectedWorkspaceId), newMemberEmail.trim(), newMemberRole)
      setNewMemberEmail('')
      setNewMemberRole('member')
      setAddingMember(false)
      await loadMemberships()
      setNotice('Member added successfully.')
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Failed to add member'
      if (msg.toLowerCase().includes('no registered user found')) {
        setError(`${msg} Use Invite to onboard this email.`)
      } else {
        setError(msg)
      }
    }
  }

  const handleInvite = async () => {
    if (!selectedWorkspaceId || !newMemberEmail.trim()) return

    setError(null)
    try {
      const invite = await workspaceMembershipService.createInvite(Number(selectedWorkspaceId), newMemberEmail.trim(), newMemberRole)
      setNewMemberEmail('')
      setNewMemberRole('member')
      setInvitingMember(false)
      await loadMemberships()
      if (invite.email_sent === false) {
        const urlHint = invite.invite_url ? ` Share link: ${invite.invite_url}` : ''
        setNotice(`Invite created, but email could not be delivered from server.${urlHint}`)
      } else {
        setNotice('Invite created and email sent successfully.')
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to create invite')
    }
  }

  const handleUpdateRole = async (membershipId: number, newRole: Role) => {
    if (!selectedWorkspaceId || !membershipId) return

    setError(null)
    try {
      await workspaceMembershipService.updateMembershipRole(Number(selectedWorkspaceId), membershipId, newRole)
      await loadMemberships()
      setNotice(`Role updated to ${newRole}.`)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update role')
    }
  }

  const handleRemoveMembership = async (membershipId: number) => {
    if (!selectedWorkspaceId || !membershipId) return
    if (!confirm('Are you sure you want to remove this member?')) return

    setError(null)
    try {
      await workspaceMembershipService.removeMembership(Number(selectedWorkspaceId), membershipId)
      await loadMemberships()
      setNotice('Member removed successfully.')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to remove member')
    }
  }

  const canManage = canManageMembers(currentUserRole)
  const isOwner = canPromoteToOwner(currentUserRole)
  const owners = memberships.filter((m) => m.role === 'owner')
  const admins = memberships.filter((m) => m.role === 'admin')
  const members = memberships.filter((m) => m.role === 'member')
  const viewers = memberships.filter((m) => m.role === 'viewer')

  const roleDropAllowed = (targetRole: Role) => {
    if (!canManage) return false
    if (targetRole === 'owner' && !isOwner) return false
    return true
  }

  const handleTreeRoleDrop = async (targetRole: Role) => {
    if (!canManage || dragMembershipId === null) return
    const dragged = memberships.find((m) => m.id === dragMembershipId)
    if (!dragged) return
    if (dragged.role === 'owner') {
      setError('Owner role cannot be changed by drag-and-drop.')
      return
    }
    if (dragged.role === targetRole) return
    await handleUpdateRole(dragMembershipId, targetRole)
  }

  const renderTreeNode = (membership: WorkspaceMembership, tone: string) => {
    const isDraggable = canManage && membership.role !== 'owner' && membership.id !== null
    const isDragging = dragMembershipId !== null && membership.id === dragMembershipId
    return (
      <div
        key={`${membership.role}-${membership.user_id}`}
        draggable={isDraggable}
        onDragStart={() => {
          if (membership.id !== null) setDragMembershipId(membership.id)
        }}
        onDragEnd={() => {
          setDragMembershipId(null)
          setDragTargetRole(null)
        }}
        className={`rounded-md border px-3 py-2 ${tone} ${isDraggable ? 'cursor-grab' : ''} ${
          isDragging ? 'opacity-60' : ''
        }`}
        title={isDraggable ? 'Drag to another role group to reassign role' : undefined}
      >
        <p className="text-sm font-semibold text-white">{membership.user.name}</p>
        <p className="text-xs text-white/70">
          {membership.user.email} · {membership.role.charAt(0).toUpperCase() + membership.role.slice(1)}
        </p>
      </div>
    )
  }

  if (!selectedWorkspaceId) {
    return (
      <div className="space-y-6">
        <div className="space-y-1 text-center">
          <h1 className="text-2xl font-semibold text-white">Workspace Members</h1>
          <p className="text-sm text-white/60">Manage team members and their roles</p>
        </div>
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-10 text-center text-white">
          <p className="text-sm text-white/60">Select a workspace to manage members</p>
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
          <h1 className="text-2xl font-semibold text-white">Workspace Members</h1>
          <p className="text-sm text-white/60">Manage team members and their roles</p>
        </div>
        <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
          {error || 'You do not have permission to view memberships for this workspace.'}
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <div className="space-y-1 text-center">
        <h1 className="text-2xl font-semibold text-white">Workspace Members</h1>
        <p className="text-sm text-white/60">Manage team members and their roles</p>
      </div>

      {error && (
        <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-200">
          {error}
        </div>
      )}
      {notice && (
        <div className="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-200">
          {notice}
        </div>
      )}

      {!canManage && (
        <div className="rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-200">
          Only workspace owners and admins can manage members
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
            <h3 className="mb-3 text-sm font-semibold text-white/80">User Tree (Main/Sub users)</h3>
            <div className="space-y-3 rounded-lg border border-white/5 bg-white/5 p-4">
              <div>
                <p className="text-xs font-semibold uppercase tracking-wide text-sky-200">Main users</p>
                <div className="mt-2 space-y-2">
                  <div
                    onDragOver={(e) => {
                      if (!roleDropAllowed('owner')) return
                      e.preventDefault()
                      setDragTargetRole('owner')
                    }}
                    onDragLeave={() => setDragTargetRole((prev) => (prev === 'owner' ? null : prev))}
                    onDrop={async (e) => {
                      if (!roleDropAllowed('owner')) return
                      e.preventDefault()
                      await handleTreeRoleDrop('owner')
                      setDragTargetRole(null)
                    }}
                    className={`space-y-2 rounded-md ${
                      dragTargetRole === 'owner' ? 'ring-1 ring-sky-400/60 bg-sky-500/5 p-1.5' : ''
                    }`}
                  >
                    {owners.map((m) => renderTreeNode(m, 'border-sky-500/20 bg-sky-500/10'))}
                  </div>
                  <div
                    onDragOver={(e) => {
                      if (!roleDropAllowed('admin')) return
                      e.preventDefault()
                      setDragTargetRole('admin')
                    }}
                    onDragLeave={() => setDragTargetRole((prev) => (prev === 'admin' ? null : prev))}
                    onDrop={async (e) => {
                      if (!roleDropAllowed('admin')) return
                      e.preventDefault()
                      await handleTreeRoleDrop('admin')
                      setDragTargetRole(null)
                    }}
                    className={`space-y-2 rounded-md ${
                      dragTargetRole === 'admin' ? 'ring-1 ring-indigo-400/60 bg-indigo-500/5 p-1.5' : ''
                    }`}
                  >
                    {admins.map((m) => renderTreeNode(m, 'border-indigo-500/20 bg-indigo-500/10'))}
                  </div>
                  {owners.length + admins.length === 0 && (
                    <p className="text-xs text-white/60">No main users defined.</p>
                  )}
                </div>
              </div>
              <div className="border-t border-white/10 pt-3">
                <p className="text-xs font-semibold uppercase tracking-wide text-emerald-200">Sub users</p>
                <div className="mt-2 space-y-2">
                  <div
                    onDragOver={(e) => {
                      if (!roleDropAllowed('member')) return
                      e.preventDefault()
                      setDragTargetRole('member')
                    }}
                    onDragLeave={() => setDragTargetRole((prev) => (prev === 'member' ? null : prev))}
                    onDrop={async (e) => {
                      if (!roleDropAllowed('member')) return
                      e.preventDefault()
                      await handleTreeRoleDrop('member')
                      setDragTargetRole(null)
                    }}
                    className={`space-y-2 rounded-md ${
                      dragTargetRole === 'member' ? 'ring-1 ring-emerald-400/60 bg-emerald-500/5 p-1.5' : ''
                    }`}
                  >
                    {members.map((m) => renderTreeNode(m, 'border-emerald-500/20 bg-emerald-500/10'))}
                  </div>
                  <div
                    onDragOver={(e) => {
                      if (!roleDropAllowed('viewer')) return
                      e.preventDefault()
                      setDragTargetRole('viewer')
                    }}
                    onDragLeave={() => setDragTargetRole((prev) => (prev === 'viewer' ? null : prev))}
                    onDrop={async (e) => {
                      if (!roleDropAllowed('viewer')) return
                      e.preventDefault()
                      await handleTreeRoleDrop('viewer')
                      setDragTargetRole(null)
                    }}
                    className={`space-y-2 rounded-md ${
                      dragTargetRole === 'viewer' ? 'ring-1 ring-amber-400/60 bg-amber-500/5 p-1.5' : ''
                    }`}
                  >
                    {viewers.map((m) => renderTreeNode(m, 'border-amber-500/20 bg-amber-500/10'))}
                  </div>
                  {members.length + viewers.length === 0 && (
                    <p className="text-xs text-white/60">No sub users defined.</p>
                  )}
                </div>
              </div>
            </div>
          </div>

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
                              handleUpdateRole(membership.id!, e.target.value as Role)
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
                      {canManage && invite.invite_url && (
                        <div className="mt-2 flex items-center gap-2">
                          <code className="rounded bg-white/5 px-2 py-1 text-[10px] text-white/80 font-mono break-all">
                            {invite.invite_url}
                          </code>
                          <button
                            type="button"
                            onClick={() => {
                              navigator.clipboard.writeText(invite.invite_url!)
                            }}
                            className="rounded-md border border-white/10 bg-white/5 px-2 py-1 text-[10px] text-white/70 transition hover:bg-white/10"
                          >
                            Copy Link
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

export default WorkspaceMembers
