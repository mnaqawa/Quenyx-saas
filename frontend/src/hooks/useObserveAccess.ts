import { useMemo } from 'react'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'
import {
  canAcknowledgeAlerts,
  canEditObserveConfig,
  canManageAlertRules,
  canRunObserveOperations,
  type Role,
} from '../rbac/permissions'

export function useObserveAccess() {
  const { selectedWorkspaceRole, modulesWithAccess, allowedByKey } = useWorkspaceContext()

  const role = (selectedWorkspaceRole ?? null) as Role | null

  const isModuleLocked = useMemo(() => {
    const observeModule = modulesWithAccess?.find((m) => m.key === 'qynsight')
    return observeModule ? !allowedByKey['qynsight'] : false
  }, [modulesWithAccess, allowedByKey])

  const isModuleEnabled = !isModuleLocked && (allowedByKey['qynsight'] ?? false)

  return {
    role,
    isModuleLocked,
    isModuleEnabled,
    canEditConfig: isModuleEnabled && canEditObserveConfig(role),
    canRunOperations: isModuleEnabled && canRunObserveOperations(role),
    canAcknowledge: isModuleEnabled && canAcknowledgeAlerts(role),
    canManageAlerts: isModuleEnabled && canManageAlertRules(role),
  }
}
