// Sprint 22 — AI Adapter Platform discovery types.
// The AI Workspace discovers modules, capabilities, and actions DYNAMICALLY from the registry — no
// hard-coded module names on the client either. Field names mirror the Laravel backend (snake_case);
// the {success,data} envelope is stripped by apiClient.

export interface AiAdapterAction {
  module?: string
  key: string
  capability: string
  target: string
  label: string
  method: string
  endpoint: string
}

export interface AiAdapterDescriptor {
  module_key: string
  module_name: string
  module_description: string
  module_category: string
  module_version: string
  module_icon: string
  capabilities: string[]
  supported_entities: string[]
  supported_skills: string[]
  supported_providers: string[]
  available_actions: AiAdapterAction[]
}

export interface AiAdaptersResponse {
  adapters: AiAdapterDescriptor[]
  count: number
}

export interface AiAdapterCapability {
  module: string
  capability: string
}

export interface AiAdapterCapabilitiesResponse {
  capabilities: AiAdapterCapability[]
  count: number
}

export interface AiAdapterActionsResponse {
  actions: AiAdapterAction[]
  count: number
}
