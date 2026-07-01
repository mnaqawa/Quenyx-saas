import { Link } from 'react-router-dom'
import { useLanguage } from '../i18n/LanguageContext'
import { PageHeader } from '../components/observe/PageHeader'

export default function HelpCenter() {
  const { t } = useLanguage()

  const sections = [
    {
      title: t('helpCenter.section.learn'),
      items: [
        { title: t('helpCenter.docs'), desc: t('helpCenter.docsDesc'), href: '/docs', external: false },
        { title: t('helpCenter.api'), desc: t('helpCenter.apiDesc'), href: '/docs/api', external: false },
        { title: t('helpCenter.releaseNotes'), desc: t('helpCenter.releaseNotesDesc'), href: '/docs/release-notes', external: false },
        { title: t('helpCenter.quickStart'), desc: t('helpCenter.quickStartDesc'), href: '/getting-started', external: false },
      ],
    },
    {
      title: t('helpCenter.section.support'),
      items: [
        { title: t('helpCenter.contact'), desc: t('helpCenter.contactDesc'), href: 'mailto:support@quenyx.com', external: true },
        { title: t('helpCenter.about'), desc: t('helpCenter.aboutDesc'), href: 'https://quenyx.com/', external: true },
      ],
    },
  ]

  return (
    <div>
      <PageHeader title={t('helpCenter.title')} subtitle={t('helpCenter.subtitle')} />

      <div className="grid gap-6 lg:grid-cols-2">
        {sections.map((section) => (
          <section key={section.title} className="rounded-2xl border border-white/10 bg-[#0f151d] p-5">
            <h2 className="text-sm font-semibold text-white">{section.title}</h2>
            <ul className="mt-4 space-y-3">
              {section.items.map((item) => (
                <li key={item.title}>
                  {item.external ? (
                    <a
                      href={item.href}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="block rounded-xl border border-white/10 bg-white/[0.03] px-4 py-3 transition hover:border-white/15 hover:bg-white/[0.06] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-500"
                    >
                      <p className="text-sm font-medium text-white">{item.title}</p>
                      <p className="mt-0.5 text-xs text-white/50">{item.desc}</p>
                    </a>
                  ) : (
                    <Link
                      to={item.href}
                      className="block rounded-xl border border-white/10 bg-white/[0.03] px-4 py-3 transition hover:border-white/15 hover:bg-white/[0.06] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-500"
                    >
                      <p className="text-sm font-medium text-white">{item.title}</p>
                      <p className="mt-0.5 text-xs text-white/50">{item.desc}</p>
                    </Link>
                  )}
                </li>
              ))}
            </ul>
          </section>
        ))}
      </div>

      <section id="documentation" className="mt-8 scroll-mt-6 rounded-2xl border border-white/10 bg-[#0f151d] p-5">
        <h2 className="text-sm font-semibold text-white">{t('helpCenter.docs')}</h2>
        <p className="mt-2 text-sm text-white/55">{t('helpCenter.docsBody')}</p>
        <Link to="/docs" className="mt-3 inline-block text-sm font-medium text-sky-300 hover:underline">
          {t('docs.openLibrary')} →
        </Link>
      </section>

      <section id="release-notes" className="mt-6 scroll-mt-6 rounded-2xl border border-white/10 bg-[#0f151d] p-5">
        <h2 className="text-sm font-semibold text-white">{t('helpCenter.releaseNotes')}</h2>
        <p className="mt-2 text-sm text-white/55">{t('helpCenter.releaseNotesBody')}</p>
        <Link to="/docs/release-notes" className="mt-3 inline-block text-sm font-medium text-sky-300 hover:underline">
          {t('helpCenter.releaseNotes')} →
        </Link>
      </section>

      <section id="about" className="mt-6 scroll-mt-6 rounded-2xl border border-white/10 bg-[#0f151d] p-5">
        <h2 className="text-sm font-semibold text-white">{t('helpCenter.about')}</h2>
        <p className="mt-2 text-sm text-white/55">{t('helpCenter.aboutBody')}</p>
        <a
          href="https://quenyx.com/"
          target="_blank"
          rel="noopener noreferrer"
          className="mt-3 inline-block text-sm font-medium text-sky-300 hover:underline"
        >
          quenyx.com →
        </a>
      </section>
    </div>
  )
}
