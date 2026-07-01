import { useCallback, useEffect, useState } from 'react'
import { useNavigate, useParams, Link } from 'react-router-dom'
import { workspaceService } from '../services/workspaceService'
import { observeService } from '../services/observeService'
import { Project, ProjectStatus, UpdateProjectInput } from '../types/project'
import { useLanguage } from '../i18n/LanguageContext'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'

const statusOptions: ProjectStatus[] = ['active', 'paused', 'archived']

const formatDate = (value: string) => new Date(value).toLocaleDateString()

function WorkspaceDetailsPage() {
  const { t } = useLanguage()
  const { id } = useParams()
  const navigate = useNavigate()
  const { setSelectedWorkspaceId } = useWorkspaceContext()
  const [project, setProject] = useState<Project | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [editing, setEditing] = useState(false)
  const [form, setForm] = useState<UpdateProjectInput>({})
  const [saving, setSaving] = useState(false)
  const [deleting, setDeleting] = useState(false)
  const [hostCount, setHostCount] = useState<number | null>(null)
  const [serviceCount, setServiceCount] = useState<number | null>(null)
  const [monitoringLoading, setMonitoringLoading] = useState(true)

  const projectId = id ? Number(id) : null

  useEffect(() => {
    if (id) {
      setSelectedWorkspaceId(id)
    }
  }, [id, setSelectedWorkspaceId])

  const loadProject = useCallback(async () => {
    if (!projectId || !Number.isFinite(projectId)) {
      setError('Invalid workspace id')
      setLoading(false)
      return
    }
    setLoading(true)
    setError(null)
    try {
      const loaded = await workspaceService.getWorkspace(projectId)
      setProject(loaded)
      setForm({ name: loaded.name, status: loaded.status })
    } catch (err) {
      setError(err instanceof Error ? err.message : 'An unexpected error occurred')
    } finally {
      setLoading(false)
    }
  }, [projectId])

  useEffect(() => {
    void loadProject()
  }, [loadProject])

  useEffect(() => {
    if (!projectId || !Number.isFinite(projectId)) {
      setHostCount(null)
      setServiceCount(null)
      setMonitoringLoading(false)
      return
    }
    let cancelled = false
    setMonitoringLoading(true)
    Promise.all([
      observeService.getTargetHosts(projectId),
      observeService.getServices(projectId, { limit: 500 }),
    ])
      .then(([hosts, services]) => {
        if (cancelled) return
        setHostCount(Array.isArray(hosts) ? hosts.length : 0)
        const items = services?.items ?? []
        setServiceCount(items.length)
      })
      .catch(() => {
        if (!cancelled) {
          setHostCount(null)
          setServiceCount(null)
        }
      })
      .finally(() => {
        if (!cancelled) setMonitoringLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [projectId])

  const handleSave = async () => {
    if (!project) return
    setSaving(true)
    setError(null)
    try {
      const payload: UpdateProjectInput = {
        name: form.name?.trim() || project.name,
        status: form.status ?? project.status,
      }
      const updatedProject = await workspaceService.updateWorkspace(project.id, payload)
      setProject(updatedProject)
      setEditing(false)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'An unexpected error occurred')
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (!project) return
    if (!window.confirm('Delete this workspace?')) return
    setDeleting(true)
    setError(null)
    try {
      await workspaceService.deleteWorkspace(project.id)
      navigate('/app/workspaces')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'An unexpected error occurred')
      setDeleting(false)
    }
  }

  if (loading) {
    return <div className="text-sm text-white/60">{t('common.loadingDashboard')}</div>
  }

  if (error) {
    return (
      <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-100">
        {error}
      </div>
    )
  }

  if (!project || !projectId) {
    return null
  }

  const observeBase = `/app/workspaces/${projectId}/observe`

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-white">{t('projects.detailsTitle')}</h1>
          <p className="text-sm text-white/60">{project.name}</p>
        </div>
        <Link to="/app/workspaces" className="text-xs text-sky-200">
          {t('projects.back')}
        </Link>
      </div>

      <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
        <div className="flex items-center justify-end gap-2">
          <button
            type="button"
            onClick={() => setEditing((prev) => !prev)}
            className="rounded-full border border-white/10 px-3 py-1 text-xs text-white/70 transition hover:bg-white/10"
          >
            {editing ? t('projects.cancel') : t('projects.edit')}
          </button>
          <button
            type="button"
            onClick={handleDelete}
            disabled={deleting}
            className="rounded-full border border-rose-500/40 px-3 py-1 text-xs text-rose-200 transition hover:bg-rose-500/10 disabled:opacity-60"
          >
            {deleting ? t('projects.deleting') : t('projects.delete')}
          </button>
        </div>

        <div className="mt-4 grid gap-4 md:grid-cols-2">
          <div>
            <p className="text-xs text-white/50">{t('projects.nameLabel')}</p>
            {editing ? (
              <input
                value={form.name ?? ''}
                onChange={(event) => setForm((prev) => ({ ...prev, name: event.target.value }))}
                className="mt-1 w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white"
              />
            ) : (
              <p className="mt-1 text-sm">{project.name}</p>
            )}
          </div>
          <div>
            <p className="text-xs text-white/50">{t('projects.statusLabel')}</p>
            {editing ? (
              <select
                value={form.status ?? project.status}
                onChange={(event) =>
                  setForm((prev) => ({ ...prev, status: event.target.value as ProjectStatus }))
                }
                className="mt-1 w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white"
              >
                {statusOptions.map((option) => (
                  <option key={option} value={option} className="text-slate-900">
                    {option}
                  </option>
                ))}
              </select>
            ) : (
              <p className="mt-1 text-sm">{project.status}</p>
            )}
          </div>
        </div>

        <div className="mt-4 flex flex-wrap gap-6 text-xs text-white/50">
          <span>Created: {formatDate(project.created_at)}</span>
          <span>Updated: {formatDate(project.updated_at)}</span>
        </div>

        {editing ? (
          <button
            type="button"
            onClick={handleSave}
            disabled={saving}
            className="mt-4 rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-400 disabled:opacity-70"
          >
            {saving ? t('projects.creating') : t('projects.save')}
          </button>
        ) : null}
      </section>

      <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
        <h2 className="text-base font-semibold">{t('workspace.monitoring.title')}</h2>
        <p className="mt-1 text-sm text-white/60">{t('workspace.monitoring.subtitle')}</p>

        <div className="mt-4 grid gap-3 sm:grid-cols-2">
          <div className="rounded-xl border border-white/10 bg-white/5 p-4">
            <p className="text-xs uppercase tracking-wider text-white/50">{t('projects.hosts')}</p>
            <p className="mt-1 text-2xl font-semibold">
              {monitoringLoading ? '—' : hostCount ?? '—'}
            </p>
          </div>
          <div className="rounded-xl border border-white/10 bg-white/5 p-4">
            <p className="text-xs uppercase tracking-wider text-white/50">{t('workspace.monitoring.services')}</p>
            <p className="mt-1 text-2xl font-semibold">
              {monitoringLoading ? '—' : serviceCount ?? '—'}
            </p>
          </div>
        </div>

        <div className="mt-4 flex flex-wrap gap-2">
          <Link
            to={`${observeBase}/targets`}
            className="rounded-lg bg-orange-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-orange-400"
          >
            {t('workspace.monitoring.manageHosts')}
          </Link>
          <Link
            to={`${observeBase}/services`}
            className="rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-sm font-medium text-white transition hover:bg-white/10"
          >
            {t('workspace.monitoring.viewServices')}
          </Link>
          <Link
            to={`${observeBase}/overview`}
            className="rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-sm font-medium text-white transition hover:bg-white/10"
          >
            {t('nav.qynsight.overview')}
          </Link>
        </div>
      </section>
    </div>
  )
}

export default WorkspaceDetailsPage
