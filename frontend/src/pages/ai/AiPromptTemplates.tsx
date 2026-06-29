import { useState } from 'react'
import { useLanguage } from '../../i18n/LanguageContext'
import { aiWorkspaceService } from '../../services/aiWorkspaceService'
import { useAiResource, useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { AiError, AiView, Card } from '../../components/ai/workspace/shared'
import type { AiPromptTemplate } from '../../types/aiWorkspace'

interface FormState {
  uuid: string | null
  name: string
  category: string
  description: string
  body: string
}

const EMPTY: FormState = { uuid: null, name: '', category: '', description: '', body: '' }

export default function AiPromptTemplates() {
  const { t } = useLanguage()
  const { workspaceUuid, hasWorkspace } = useAiWorkspaceUuid()
  const { data, loading, error, reload } = useAiResource((uuid) => aiWorkspaceService.listTemplates(uuid))
  const [form, setForm] = useState<FormState>(EMPTY)
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)

  const startEdit = (tpl: AiPromptTemplate) =>
    setForm({
      uuid: tpl.uuid,
      name: tpl.name,
      category: tpl.category ?? '',
      description: tpl.description ?? '',
      body: tpl.body,
    })

  const save = async () => {
    if (!workspaceUuid || form.name.trim() === '' || form.body.trim() === '') return
    setSaving(true)
    setFormError(null)
    try {
      const payload = {
        name: form.name.trim(),
        body: form.body,
        category: form.category.trim() || null,
        description: form.description.trim() || null,
      }
      if (form.uuid) {
        await aiWorkspaceService.updateTemplate(workspaceUuid, form.uuid, payload)
      } else {
        await aiWorkspaceService.createTemplate(workspaceUuid, payload)
      }
      setForm(EMPTY)
      reload()
    } catch (err: unknown) {
      setFormError(err instanceof Error ? err.message : t('aiWorkspace.common.saveError'))
    } finally {
      setSaving(false)
    }
  }

  const remove = async (uuid: string) => {
    if (!workspaceUuid) return
    try {
      await aiWorkspaceService.deleteTemplate(workspaceUuid, uuid)
      if (form.uuid === uuid) setForm(EMPTY)
      reload()
    } catch {
      /* surfaced on reload */
    }
  }

  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
      <div>
        <AiView
          hasWorkspace={hasWorkspace}
          loading={loading}
          error={error}
          data={data}
          onRetry={reload}
          isEmpty={(rows) => rows.length === 0}
          emptyTitle={t('aiWorkspace.templates.emptyTitle')}
          emptyDescription={t('aiWorkspace.templates.emptyDescription')}
        >
          {(rows) => (
            <div className="space-y-3">
              {rows.map((tpl) => (
                <Card key={tpl.uuid} className="space-y-2">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <h3 className="text-sm font-semibold text-white">{tpl.name}</h3>
                      {tpl.category ? <p className="text-xs text-white/40">{tpl.category}</p> : null}
                    </div>
                    <div className="flex shrink-0 gap-2">
                      <button onClick={() => startEdit(tpl)} className="text-xs text-sky-300 hover:underline">
                        {t('aiWorkspace.common.edit')}
                      </button>
                      <button onClick={() => void remove(tpl.uuid)} className="text-xs text-rose-300 hover:underline">
                        {t('aiWorkspace.common.delete')}
                      </button>
                    </div>
                  </div>
                  {tpl.description ? <p className="text-xs text-white/50">{tpl.description}</p> : null}
                  <pre className="max-h-32 overflow-auto whitespace-pre-wrap rounded-lg bg-black/30 p-2 text-xs text-white/70">{tpl.body}</pre>
                </Card>
              ))}
            </div>
          )}
        </AiView>
      </div>

      {hasWorkspace ? (
        <Card className="h-fit space-y-3">
          <h3 className="text-sm font-semibold text-white">
            {form.uuid ? t('aiWorkspace.templates.editTitle') : t('aiWorkspace.templates.newTitle')}
          </h3>
          {formError ? <AiError message={formError} /> : null}
          <input
            value={form.name}
            onChange={(e) => setForm({ ...form, name: e.target.value })}
            placeholder={t('aiWorkspace.templates.name')}
            className="w-full rounded-lg border border-white/10 bg-[#0b0f14] px-3 py-2 text-sm text-white placeholder:text-white/30 focus:border-sky-400 focus:outline-none"
          />
          <input
            value={form.category}
            onChange={(e) => setForm({ ...form, category: e.target.value })}
            placeholder={t('aiWorkspace.templates.category')}
            className="w-full rounded-lg border border-white/10 bg-[#0b0f14] px-3 py-2 text-sm text-white placeholder:text-white/30 focus:border-sky-400 focus:outline-none"
          />
          <input
            value={form.description}
            onChange={(e) => setForm({ ...form, description: e.target.value })}
            placeholder={t('aiWorkspace.templates.description')}
            className="w-full rounded-lg border border-white/10 bg-[#0b0f14] px-3 py-2 text-sm text-white placeholder:text-white/30 focus:border-sky-400 focus:outline-none"
          />
          <textarea
            value={form.body}
            onChange={(e) => setForm({ ...form, body: e.target.value })}
            placeholder={t('aiWorkspace.templates.body')}
            rows={6}
            className="w-full resize-none rounded-lg border border-white/10 bg-[#0b0f14] px-3 py-2 text-sm text-white placeholder:text-white/30 focus:border-sky-400 focus:outline-none"
          />
          <div className="flex gap-2">
            <button
              onClick={() => void save()}
              disabled={saving || form.name.trim() === '' || form.body.trim() === ''}
              className="rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white hover:bg-sky-400 disabled:opacity-40"
            >
              {saving ? t('aiWorkspace.common.saving') : t('aiWorkspace.common.save')}
            </button>
            {form.uuid ? (
              <button onClick={() => setForm(EMPTY)} className="rounded-full border border-white/15 px-4 py-2 text-xs font-semibold text-white/70 hover:bg-white/10">
                {t('aiWorkspace.common.cancel')}
              </button>
            ) : null}
          </div>
        </Card>
      ) : null}
    </div>
  )
}
