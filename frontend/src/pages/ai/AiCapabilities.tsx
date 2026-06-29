import { useLanguage } from '../../i18n/LanguageContext'
import { aiWorkspaceService } from '../../services/aiWorkspaceService'
import { useAiResource, useAiWorkspaceUuid } from '../../hooks/useAiWorkspace'
import { AiView, Card, formatDateTime } from '../../components/ai/workspace/shared'

function Chips({ items }: { items: string[] }) {
  if (items.length === 0) return <span className="text-xs text-white/40">—</span>
  return (
    <div className="flex flex-wrap gap-1.5">
      {items.map((i) => (
        <span key={i} className="rounded-full bg-white/10 px-2 py-0.5 text-[11px] text-white/70">{i}</span>
      ))}
    </div>
  )
}

export default function AiCapabilities() {
  const { t } = useLanguage()
  const { hasWorkspace } = useAiWorkspaceUuid()
  const { data, loading, error, reload } = useAiResource((uuid) => aiWorkspaceService.getCapabilities(uuid))

  return (
    <AiView hasWorkspace={hasWorkspace} loading={loading} error={error} data={data} onRetry={reload}>
      {(c) => (
        <div className="space-y-4">
          <Card className="space-y-3">
            <h3 className="text-sm font-semibold text-white">{t('aiWorkspace.capabilities.providers')}</h3>
            <div className="grid gap-3 sm:grid-cols-3">
              <div>
                <p className="text-xs text-white/40">{t('aiWorkspace.capabilities.default')}</p>
                <Chips items={[c.providers.default]} />
              </div>
              <div>
                <p className="text-xs text-white/40">{t('aiWorkspace.capabilities.available')}</p>
                <Chips items={c.providers.available} />
              </div>
              <div>
                <p className="text-xs text-white/40">{t('aiWorkspace.capabilities.implemented')}</p>
                <Chips items={c.providers.implemented} />
              </div>
            </div>
          </Card>

          <div className="grid gap-4 md:grid-cols-2">
            <Card className="space-y-2">
              <h3 className="text-sm font-semibold text-white">{t('aiWorkspace.capabilities.retrieval')}</h3>
              <Chips items={c.retrieval.modes} />
            </Card>
            <Card className="space-y-2">
              <h3 className="text-sm font-semibold text-white">{t('aiWorkspace.capabilities.reasoning')}</h3>
              <Chips items={c.reasoning.decision_types} />
            </Card>
            <Card className="space-y-2">
              <h3 className="text-sm font-semibold text-white">{t('aiWorkspace.capabilities.rag')}</h3>
              <p className="text-xs text-white/60">
                {t('aiWorkspace.common.status')}: {c.rag.enabled ? t('aiWorkspace.common.on') : t('aiWorkspace.common.off')}
                {c.rag.vector_provider ? ` · ${c.rag.vector_provider}` : ''}
              </p>
            </Card>
            <Card className="space-y-2">
              <h3 className="text-sm font-semibold text-white">{t('aiWorkspace.capabilities.contexts')}</h3>
              <Chips items={c.supported_contexts} />
            </Card>
          </div>

          <Card className="space-y-3">
            <h3 className="text-sm font-semibold text-white">{t('aiWorkspace.capabilities.modules')}</h3>
            <div className="space-y-3">
              {c.modules.map((m) => (
                <div key={m.key} className="rounded-lg border border-white/5 bg-white/5 p-3">
                  <p className="text-sm font-medium text-white">{m.key}</p>
                  <div className="mt-2"><Chips items={m.supported_skills} /></div>
                </div>
              ))}
            </div>
          </Card>

          <p className="text-xs text-white/30">{t('aiWorkspace.capabilities.generatedAt')}: {formatDateTime(c.generated_at)}</p>
        </div>
      )}
    </AiView>
  )
}
