import { useEffect, useState } from 'react'
import { useProjectContext } from '../projects/ProjectContext'
import { projectMemberService, ProjectMember } from '../services/projectMemberService'

function ProjectMembers() {
  const { selectedProjectId } = useProjectContext()
  const [members, setMembers] = useState<ProjectMember[]>([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [addingMember, setAddingMember] = useState(false)
  const [newMemberEmail, setNewMemberEmail] = useState('')
  const [newMemberRole, setNewMemberRole] = useState<'admin' | 'member' | 'viewer'>('member')
  const [userRole, setUserRole] = useState<string | null>(null)

  useEffect(() => {
    if (selectedProjectId) {
      loadMembers()
    } else {
      setMembers([])
    }
  }, [selectedProjectId])

  const loadMembers = async () => {
    if (!selectedProjectId) return

    setLoading(true)
    setError(null)
    try {
      const response = await projectMemberService.getProjectMembers(selectedProjectId)
      if (response.success) {
        setMembers(response.data)
        // Determine current user's role (owner is first in list, or check members)
        const owner = response.data.find((m) => m.role === 'owner')
        if (owner) {
          // For now, assume user is owner if they can see this page
          // In real app, get from auth context
          setUserRole('owner')
        }
      } else {
        setError(response.message || 'Failed to load members')
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load members')
    } finally {
      setLoading(false)
    }
  }

  const handleAddMember = async () => {
    if (!selectedProjectId || !newMemberEmail.trim()) return

    setError(null)
    try {
      const response = await projectMemberService.addMember(
        selectedProjectId,
        newMemberEmail.trim(),
        newMemberRole
      )
      if (response.success) {
        setNewMemberEmail('')
        setNewMemberRole('member')
        setAddingMember(false)
        await loadMembers()
      } else {
        setError(response.message || 'Failed to add member')
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to add member')
    }
  }

  const handleUpdateRole = async (userId: number, newRole: 'admin' | 'member' | 'viewer') => {
    if (!selectedProjectId) return

    setError(null)
    try {
      const response = await projectMemberService.updateMemberRole(selectedProjectId, userId, newRole)
      if (response.success) {
        await loadMembers()
      } else {
        setError(response.message || 'Failed to update role')
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to update role')
    }
  }

  const handleRemoveMember = async (userId: number) => {
    if (!selectedProjectId) return
    if (!confirm('Are you sure you want to remove this member?')) return

    setError(null)
    try {
      const response = await projectMemberService.removeMember(selectedProjectId, userId)
      if (response.success) {
        await loadMembers()
      } else {
        setError(response.message || 'Failed to remove member')
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to remove member')
    }
  }

  const canManage = userRole === 'owner' || userRole === 'admin'

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
          <div className="mb-4">
            {!addingMember ? (
              <button
                type="button"
                onClick={() => setAddingMember(true)}
                className="rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-400"
              >
                + Add Member
              </button>
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
                    onClick={handleAddMember}
                    className="rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-400"
                  >
                    Add
                  </button>
                  <button
                    type="button"
                    onClick={() => {
                      setAddingMember(false)
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

        <div className="space-y-3">
          {members.length === 0 ? (
            <div className="text-sm text-white/60">No members found</div>
          ) : (
            members.map((member) => (
              <div
                key={member.user_id}
                className="flex items-center justify-between rounded-lg border border-white/5 bg-white/5 p-4"
              >
                <div>
                  <p className="font-semibold">{member.user.name}</p>
                  <p className="text-xs text-white/60">{member.user.email}</p>
                </div>
                <div className="flex items-center gap-3">
                  {canManage && member.role !== 'owner' ? (
                    <>
                      <select
                        value={member.role}
                        onChange={(e) =>
                          handleUpdateRole(member.user_id, e.target.value as 'admin' | 'member' | 'viewer')
                        }
                        className="rounded-md border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-white focus:border-sky-500 focus:outline-none"
                      >
                        <option value="admin">Admin</option>
                        <option value="member">Member</option>
                        <option value="viewer">Viewer</option>
                      </select>
                      <button
                        type="button"
                        onClick={() => handleRemoveMember(member.user_id)}
                        className="rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-1.5 text-xs text-rose-200 transition hover:bg-rose-500/20"
                      >
                        Remove
                      </button>
                    </>
                  ) : (
                    <span className="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs text-white/70">
                      {member.role.charAt(0).toUpperCase() + member.role.slice(1)}
                    </span>
                  )}
                </div>
              </div>
            ))
          )}
        </div>
      </div>
    </div>
  )
}

export default ProjectMembers
