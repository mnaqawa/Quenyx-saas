import { useLanguage } from '../../i18n/LanguageContext'
import { aiWorkspaceService } from '../../services/aiWorkspaceService'
import { useAiResource, useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { AiView, Card } from '../../components/ai/workspace/shared'

export default function AiSkills() {
  const { t } = useLanguage()
  const { hasWorkspace } = useAiWorkspaceUuid()
  const { data, loading, error, reload } = useAiResource((uuid) => aiWorkspaceService.getSkills(uuid))

  return (
    <AiView
      hasWorkspace={hasWorkspace}
      loading={loading}
      error={error}
      data={data}
      onRetry={reload}
      isEmpty={(res) => res.skills.length === 0}
      emptyTitle={t('aiWorkspace.skills.emptyTitle')}
      emptyDescription={t('aiWorkspace.skills.emptyDescription')}
    >
      {(res) => (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {res.skills.map((skill, idx) => {
            const key = String(skill.key ?? skill.name ?? idx)
            const enabled = skill.enabled !== false
            return (
              <Card key={key} className="space-y-2">
                <div className="flex items-center justify-between">
                  <h3 className="text-sm font-semibold text-white">{String(skill.name ?? skill.key ?? key)}</h3>
                  <span
                    className={[
                      'rounded-full px-2 py-0.5 text-[10px] font-semibold',
                      enabled ? 'bg-emerald-500/15 text-emerald-300' : 'bg-white/10 text-white/50',
                    ].join(' ')}
                  >
                    {enabled ? t('aiWorkspace.common.enabled') : t('aiWorkspace.common.disabled')}
                  </span>
                </div>
                {skill.description ? <p className="text-xs text-white/50">{String(skill.description)}</p> : null}
              </Card>
            )
          })}
        </div>
      )}
    </AiView>
  )
}
