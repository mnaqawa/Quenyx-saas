import { useEffect, useState } from 'react'
import { useLanguage } from '../../../i18n/LanguageContext'

interface AiChatTypingIndicatorProps {
  knowledgeBase?: boolean
}

/**
 * Visible “AI is working” state for long knowledge-base requests (File Search can take 30–120s).
 */
export function AiChatTypingIndicator({ knowledgeBase = false }: AiChatTypingIndicatorProps) {
  const { t } = useLanguage()
  const [stage, setStage] = useState(0)

  const stages = knowledgeBase
    ? [
        t('aiWorkspace.chat.statusSearching'),
        t('aiWorkspace.chat.statusAnalyzing'),
        t('aiWorkspace.chat.statusGenerating'),
      ]
    : [t('aiWorkspace.chat.statusThinking'), t('aiWorkspace.chat.statusGenerating')]

  useEffect(() => {
    setStage(0)
    const id = window.setInterval(() => {
      setStage((s) => (s + 1) % stages.length)
    }, 8000)
    return () => window.clearInterval(id)
  }, [stages.length, knowledgeBase])

  return (
    <div className="flex flex-col items-start" aria-live="polite" aria-busy="true">
      <div className="flex max-w-[85%] items-center gap-3 rounded-2xl rounded-es-sm border border-sky-400/25 bg-sky-500/10 px-4 py-3">
        <div className="flex items-center gap-1" aria-hidden="true">
          <span className="h-2 w-2 animate-bounce rounded-full bg-sky-300 [animation-delay:-0.3s]" />
          <span className="h-2 w-2 animate-bounce rounded-full bg-sky-300 [animation-delay:-0.15s]" />
          <span className="h-2 w-2 animate-bounce rounded-full bg-sky-300" />
        </div>
        <span className="text-sm text-sky-100">{stages[stage]}</span>
      </div>
      <span className="mt-1 px-1 text-[10px] text-white/40">{t('aiWorkspace.chat.statusHint')}</span>
    </div>
  )
}
