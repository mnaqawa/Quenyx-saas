import { useEffect, useState } from 'react'
import { useLanguage } from '../../i18n/LanguageContext'
import { aiWorkspaceService } from '../../services/aiWorkspaceService'
import { useAiResource, useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { AiError, AiView, Card } from '../../components/ai/workspace/shared'
import type { AiPermissionRule } from '../../types/aiWorkspace'

const FLAGS: Array<keyof AiPermissionRule> = [
  'can_use_ai',
  'can_manage_templates',
  'can_manage_providers',
  'can_view_costs',
  'can_administer',
]

export default function AiPermissions() {
  const { t } = useLanguage()
  const { workspaceUuid, hasWorkspace } = useAiWorkspaceUuid()
  const { data, loading, error, reload } = useAiResource((uuid) => aiWorkspaceService.getPermissions(uuid))
  const [rules, setRules] = useState<AiPermissionRule[]>([])
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)

  useEffect(() => {
    if (data?.roles) setRules(data.roles)
  }, [data])

  const toggle = (roleIdx: number, flag: keyof AiPermissionRule) => {
    setRules((prev) =>
      prev.map((r, i) => (i === roleIdx ? { ...r, [flag]: !(r[flag] as boolean) } : r))
    )
  }

  const save = async () => {
    if (!workspaceUuid) return
    setSaving(true)
    setFormError(null)
    try {
      const res = await aiWorkspaceService.updatePermissions(workspaceUuid, rules)
      setRules(res.roles)
    } catch (err: unknown) {
      setFormError(err instanceof Error ? err.message : t('aiWorkspace.common.saveError'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <AiView hasWorkspace={hasWorkspace} loading={loading} error={error} data={data} onRetry={reload}>
      {() => (
        <div className="space-y-4">
          {formError ? <AiError message={formError} /> : null}
          <Card className="overflow-x-auto p-0">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-xs uppercase tracking-wide text-white/40">
                  <th className="px-4 py-3 text-start">{t('aiWorkspace.permissions.role')}</th>
                  {FLAGS.map((f) => (
                    <th key={f} className="px-3 py-3 text-center">{t(`aiWorkspace.permissions.${f}`)}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-white/5">
                {rules.map((r, idx) => (
                  <tr key={r.role}>
                    <td className="px-4 py-3 font-medium text-white">{r.role}</td>
                    {FLAGS.map((f) => (
                      <td key={f} className="px-3 py-3 text-center">
                        <input
                          type="checkbox"
                          checked={Boolean(r[f])}
                          onChange={() => toggle(idx, f)}
                          disabled={r.role === 'owner'}
                        />
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>
          <button
            onClick={() => void save()}
            disabled={saving}
            className="rounded-full bg-sky-500 px-4 py-2 text-xs font-semibold text-white hover:bg-sky-400 disabled:opacity-40"
          >
            {saving ? t('aiWorkspace.common.saving') : t('aiWorkspace.permissions.save')}
          </button>
        </div>
      )}
    </AiView>
  )
}
