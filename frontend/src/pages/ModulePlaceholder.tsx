import { useParams } from 'react-router-dom'
import ComingSoon from './ComingSoon'
import { useWorkspaceContext } from '../workspaces/WorkspaceContext'
import { getModuleByKey } from '../constants/modules'

// Wrapper component to extract moduleKey from route params
export default function ModulePlaceholder() {
  const { moduleKey } = useParams<{ moduleKey: string }>()
  const { allowedByKey } = useWorkspaceContext()
  
  if (!moduleKey) {
    return <ComingSoon moduleKey="unknown" />
  }

  const moduleConfig = getModuleByKey(moduleKey)
  const isLocked = !allowedByKey[moduleKey]

  return (
    <ComingSoon 
      moduleKey={moduleKey}
      moduleName={moduleConfig?.displayName}
      description={moduleConfig?.description}
      isLocked={isLocked}
    />
  )
}
