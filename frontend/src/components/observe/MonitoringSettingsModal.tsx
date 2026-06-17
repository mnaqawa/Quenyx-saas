import { useLanguage } from '../../i18n/LanguageContext'
import { MonitoringThresholdsPanel } from './MonitoringThresholdsPanel'

interface MonitoringSettingsModalProps {
  open: boolean
  onClose: () => void
  workspaceId: string | number
  canEdit: boolean
}

export function MonitoringSettingsModal({ open, onClose, workspaceId, canEdit }: MonitoringSettingsModalProps) {
  const { t } = useLanguage()

  if (!open) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
      <div className="flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-white/10 bg-[#0f151d] text-white shadow-xl">
        <div className="flex items-center justify-between border-b border-white/10 px-6 py-4">
          <h3 className="text-base font-semibold">{t('targets.monitoringSettings')}</h3>
          <button
            type="button"
            onClick={onClose}
            className="rounded-lg border border-white/10 bg-white/5 px-3 py-1 text-xs text-white/70 hover:bg-white/10"
          >
            {t('common.close')}
          </button>
        </div>
        <div className="overflow-y-auto p-6">
          <MonitoringThresholdsPanel workspaceId={workspaceId} canEdit={canEdit} embedded />
        </div>
      </div>
    </div>
  )
}
