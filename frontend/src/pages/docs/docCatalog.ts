/** Maps friendly URL slugs to bundled HTML documentation files (build/docs-html). */
export interface DocEntry {
  slug: string
  file: string
  titleKey: string
  descKey: string
  category: 'guides' | 'reference' | 'release'
}

export const DOC_ENTRIES: DocEntry[] = [
  {
    slug: 'developer-guide',
    file: '11_DEVELOPER_GUIDE.html',
    titleKey: 'docs.catalog.developer',
    descKey: 'docs.catalog.developerDesc',
    category: 'guides',
  },
  {
    slug: 'administrator-guide',
    file: '12_ADMINISTRATOR_GUIDE.html',
    titleKey: 'docs.catalog.administrator',
    descKey: 'docs.catalog.administratorDesc',
    category: 'guides',
  },
  {
    slug: 'customer-guide',
    file: '13_CUSTOMER_USER_GUIDE.html',
    titleKey: 'docs.catalog.customer',
    descKey: 'docs.catalog.customerDesc',
    category: 'guides',
  },
  {
    slug: 'deployment-guide',
    file: '10_DEPLOYMENT_GUIDE.html',
    titleKey: 'docs.catalog.deployment',
    descKey: 'docs.catalog.deploymentDesc',
    category: 'guides',
  },
  {
    slug: 'api',
    file: '08_API_REFERENCE.html',
    titleKey: 'helpCenter.api',
    descKey: 'helpCenter.apiDesc',
    category: 'reference',
  },
  {
    slug: 'database-reference',
    file: '09_DATABASE_REFERENCE.html',
    titleKey: 'docs.catalog.database',
    descKey: 'docs.catalog.databaseDesc',
    category: 'reference',
  },
  {
    slug: 'release-notes',
    file: '39_RELEASE_NOTES_v1.0.html',
    titleKey: 'helpCenter.releaseNotes',
    descKey: 'helpCenter.releaseNotesDesc',
    category: 'release',
  },
]

const bySlug = new Map(DOC_ENTRIES.map((d) => [d.slug, d]))

export function resolveDoc(slug: string | undefined): DocEntry | null {
  if (!slug) return null
  return bySlug.get(slug) ?? null
}

export function docAssetUrl(file: string): string {
  return `/docs/${file}`
}
