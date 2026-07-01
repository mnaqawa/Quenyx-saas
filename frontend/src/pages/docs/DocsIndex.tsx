import { Link } from 'react-router-dom'
import { useLanguage } from '../../i18n/LanguageContext'
import { PageHeader } from '../../components/observe/PageHeader'
import { DOC_ENTRIES } from './docCatalog'

export default function DocsIndex() {
  const { t } = useLanguage()

  const groups = [
    { key: 'guides', title: t('docs.section.guides') },
    { key: 'reference', title: t('docs.section.reference') },
    { key: 'release', title: t('docs.section.release') },
  ] as const

  return (
    <div>
      <PageHeader title={t('docs.title')} subtitle={t('docs.subtitle')} />
      <p className="mb-6 text-sm">
        <Link to="/help-center" className="text-sky-300 hover:underline">
          ← {t('nav.helpCenter')}
        </Link>
      </p>

      <div className="space-y-8">
        {groups.map((group) => {
          const items = DOC_ENTRIES.filter((d) => d.category === group.key)
          if (items.length === 0) return null
          return (
            <section key={group.key} className="rounded-2xl border border-white/10 bg-[#0f151d] p-5">
              <h2 className="text-sm font-semibold text-white">{group.title}</h2>
              <ul className="mt-4 grid gap-3 sm:grid-cols-2">
                {items.map((doc) => (
                  <li key={doc.slug}>
                    <Link
                      to={`/docs/${doc.slug}`}
                      className="block rounded-xl border border-white/10 bg-white/[0.03] px-4 py-3 transition hover:border-sky-400/30 hover:bg-white/[0.06]"
                    >
                      <p className="text-sm font-medium text-white">{t(doc.titleKey)}</p>
                      <p className="mt-0.5 text-xs text-white/50">{t(doc.descKey)}</p>
                    </Link>
                  </li>
                ))}
              </ul>
            </section>
          )
        })}
      </div>
    </div>
  )
}
