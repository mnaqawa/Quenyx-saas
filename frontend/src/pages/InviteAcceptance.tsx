import { useState, useEffect } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { inviteService } from '../services/inviteService'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'
import { getAuthToken } from '../services/apiClient'
import { getRequestErrorStatus } from '../lib/requestError'

function InviteAcceptance() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const { setSelectedWorkspaceId } = useWorkspaceContext()
  const tokenFromQuery = searchParams.get('token') || ''
  const [token, setToken] = useState(tokenFromQuery)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState(false)
  const [autoAttempted, setAutoAttempted] = useState(false)

  // Redirect to login if not authenticated, preserving return path + token
  useEffect(() => {
    if (!getAuthToken()) {
      const currentPath = `/invites/accept${tokenFromQuery ? `?token=${tokenFromQuery}` : ''}`
      navigate(`/login?next=${encodeURIComponent(currentPath)}`, { replace: true })
      return
    }
  }, [navigate, tokenFromQuery])

  // Auto-attempt accept on page load if authenticated and token exists in query
  useEffect(() => {
    const authToken = getAuthToken()
    if (authToken && tokenFromQuery && !autoAttempted && !success && !loading) {
      setAutoAttempted(true)
      // Use a separate async function to avoid dependency issues
      const attemptAccept = async () => {
        await handleAccept(tokenFromQuery, true)
      }
      attemptAccept()
    }
  }, [tokenFromQuery, autoAttempted, success, loading]) // eslint-disable-line react-hooks/exhaustive-deps

  const handleAccept = async (tokenToUse?: string, isAutoAttempt = false) => {
    const tokenValue = tokenToUse || token.trim()
    if (!tokenValue) {
      if (!isAutoAttempt) {
        setError('Please enter an invite token')
      }
      return
    }

    setLoading(true)
    setError(null)

    try {
      const response = await inviteService.acceptInvite(tokenValue)
      
      // Set the project as the selected project
      setSelectedWorkspaceId(response.project.id)
      
      setSuccess(true)
      
      // Navigate to the project after a short delay
              setTimeout(() => {
                navigate(`/app/workspaces/${response.project.id}`, { replace: true })
              }, 1500)
    } catch (err) {
      if (err instanceof Error) {
        const status = getRequestErrorStatus(err)
        if (status === 404) {
          setError('Invite not found. Please check the token and try again.')
        } else if (status === 403) {
          setError('This invite is for a different email address. Please log in with the correct account.')
        } else if (status === 409) {
          setError('This invite has already been accepted or is no longer valid.')
        } else {
          setError(err.message || 'Failed to accept invite')
        }
      } else {
        setError('An unexpected error occurred')
      }
    } finally {
      setLoading(false)
    }
  }

  if (success) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-100 px-4 py-8">
        <div className="w-full max-w-md rounded-lg border border-slate-200 bg-white p-6 shadow-sm text-center">
          <div className="mb-4 text-4xl">✓</div>
          <h1 className="text-2xl font-semibold text-slate-900">Invite Accepted!</h1>
          <p className="mt-2 text-sm text-slate-600">Redirecting to your workspace...</p>
        </div>
      </div>
    )
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-100 px-4 py-8">
      <div className="w-full max-w-md rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        <div className="space-y-2">
          <h1 className="text-2xl font-semibold text-slate-900">Accept Invite</h1>
          <p className="text-sm text-slate-600">Enter your invite token to join a workspace</p>
        </div>

        <div className="mt-6 space-y-4">
          <div className="space-y-1">
            <label className="text-sm font-medium text-slate-700" htmlFor="token">
              Invite Token
            </label>
            <input
              id="token"
              type="text"
              required
              value={token}
              onChange={(event) => setToken(event.target.value)}
              className="w-full rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200"
              placeholder="Enter invite token"
            />
            <p className="text-xs text-slate-500">
              You should have received this token from the workspace owner
            </p>
          </div>

          {error && (
            <div className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
              {error}
            </div>
          )}

          <button
            type="button"
            onClick={() => handleAccept()}
            disabled={loading || !token.trim()}
            className="w-full rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:bg-indigo-300"
          >
            {loading ? 'Accepting...' : 'Accept Invite'}
          </button>
        </div>
      </div>
    </div>
  )
}

export default InviteAcceptance
