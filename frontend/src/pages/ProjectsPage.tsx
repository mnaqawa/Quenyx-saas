import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { projectService } from '../services/projectService'
import { CreateProjectInput, Project, ProjectStatus } from '../types/project'
import { useLanguage } from '../i18n/LanguageContext'

const statusOptions: ProjectStatus[] = ['active', 'paused', 'archived']

const formatDate = (value: string) => {
  return new Date(value).toLocaleDateString()
}

function ProjectsPage() {
  const { t } = useLanguage()
  const [projects, setProjects] = useState<Project[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [form, setForm] = useState<CreateProjectInput>({ name: '', status: 'active' })
  const [creating, setCreating] = useState(false)
  const [success, setSuccess] = useState<string | null>(null)

  const loadProjects = async () => {
    setLoading(true)
    setError(null)
    const response = await projectService.listProjects()
    if (!response.success) {
      setError(response.message)
      setLoading(false)
      return
    }
    setProjects(response.data)
    setLoading(false)
  }

  useEffect(() => {
    loadProjects()
  }, [])

  const handleCreate = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setCreating(true)
    setSuccess(null)
    const response = await projectService.createProject({
      name: form.name.trim(),
      status: form.status,
    })
    if (!response.success) {
      setError(response.message)
      setCreating(false)
      return
    }
    setForm({ name: '', status: 'active' })
    setSuccess(t('projects.createTitle'))
    await loadProjects()
    setCreating(false)
  }

  const orderedProjects = useMemo(() => {
    return [...projects].sort((a, b) => b.updated_at.localeCompare(a.updated_at))
  }, [projects])

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

  return (
    <div className="space-y-6">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold text-white">{t('projects.title')}</h1>
        <p className="text-sm text-white/60">{t('projects.subtitle')}</p>
      </div>

      <section className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white">
        <h2 className="text-sm font-semibold">{t('projects.createTitle')}</h2>
        <form onSubmit={handleCreate} className="mt-4 grid gap-4 md:grid-cols-[2fr,1fr,auto]">
          <div className="space-y-1">
            <label className="text-xs text-white/60">{t('projects.nameLabel')}</label>
            <input
              value={form.name}
              required
              minLength={2}
              onChange={(event) => setForm((prev) => ({ ...prev, name: event.target.value }))}
              className="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white"
              placeholder="New project"
            />
          </div>
          <div className="space-y-1">
            <label className="text-xs text-white/60">{t('projects.statusLabel')}</label>
            <select
              value={form.status}
              onChange={(event) =>
                setForm((prev) => ({ ...prev, status: event.target.value as ProjectStatus }))
              }
              className="w-full rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-sm text-white"
            >
              {statusOptions.map((option) => (
                <option key={option} value={option} className="text-slate-900">
                  {option}
                </option>
              ))}
            </select>
          </div>
          <div className="flex items-end">
            <button
              type="submit"
              disabled={creating}
              className="rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white transition hover:bg-sky-400 disabled:cursor-not-allowed disabled:opacity-70"
            >
              {creating ? t('projects.creating') : t('projects.createButton')}
            </button>
          </div>
        </form>
        {success ? <p className="mt-3 text-xs text-emerald-300">{success}</p> : null}
      </section>

      {orderedProjects.length === 0 ? (
        <div className="rounded-2xl border border-white/10 bg-[#0f151d] px-6 py-10 text-center text-white">
          <h3 className="text-sm font-semibold">{t('projects.emptyTitle')}</h3>
          <p className="mt-2 text-xs text-white/60">{t('projects.emptySubtitle')}</p>
        </div>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {orderedProjects.map((project) => (
            <Link
              key={project.id}
              to={`/app/projects/${project.id}`}
              className="rounded-2xl border border-white/10 bg-[#0f151d] p-5 text-white transition hover:border-white/20"
            >
              <div className="flex items-start justify-between gap-3">
                <div>
                  <h3 className="text-sm font-semibold">{project.name}</h3>
                  <p className="text-xs text-white/50">{project.status}</p>
                </div>
                <span className="rounded-full border border-white/10 px-3 py-1 text-[10px] text-white/60">
                  {t('projects.updatedAt')} {formatDate(project.updated_at)}
                </span>
              </div>
              <div className="mt-4 text-xs text-sky-200">{t('projects.viewDetails')}</div>
            </Link>
          ))}
        </div>
      )}
    </div>
  )
}

export default ProjectsPage
