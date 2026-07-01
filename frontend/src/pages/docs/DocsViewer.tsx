import { Link, useParams } from 'react-router-dom'
import { useLanguage } from '../../i18n/LanguageContext'
import { docAssetUrl, resolveDoc } from './docCatalog'

export default function DocsViewer() {
  const { slug = '' } = useParams<{ slug: string }>()
  const { t } = useLanguage()
  const doc = resolveDoc(slug)

  if (!doc) {
    return (
      <div className="rounded-2xl border border-white/10 bg-[#0f151d] p-6 text-white">
        <p className="text-sm text-white/70">{t('docs.notFound')}</p>
        <Link to="/docs" className="mt-4 inline-block text-sm text-sky-300 hover:underline">
          ← {t('docs.title')}
        </Link>
      </div>
    )
  }

  return (
    <div className="flex min-h-[calc(100vh-8rem)] flex-col">
      <div className="mb-3 flex flex-wrap items-center gap-3 text-sm">
        <Link to="/help-center" className="text-sky-300 hover:underline">
          {t('nav.helpCenter')}
        </Link>
        <span className="text-white/30">/</span>
        <Link to="/docs" className="text-sky-300 hover:underline">
          {t('docs.title')}
        </Link>
        <span className="text-white/30">/</span>
        <span className="text-white/80">{t(doc.titleKey)}</span>
      </div>

      <div className="min-h-0 flex-1 overflow-hidden rounded-2xl border border-white/10 bg-white shadow-lg">
        <iframe
          title={t(doc.titleKey)}
          src={docAssetUrl(doc.file)}
          className="h-full min-h-[70vh] w-full border-0"
        />
      </div>
    </div>
  )
}
