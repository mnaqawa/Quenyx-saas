export interface ModuleCatalog {
  key: string
  name: string
  description?: string | null
  status?: string
}

export interface ModuleWithAccess extends ModuleCatalog {
  allowed: boolean
  allowed_by_plan?: boolean
  override?: 'allow' | 'deny' | null
}

export interface ProjectModuleAccess {
  plan: {
    key: string
    name: string
  }
  modules: Array<{
    key: string
    allowed: boolean
  }>
}
