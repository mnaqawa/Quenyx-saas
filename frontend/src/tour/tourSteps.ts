export type TourPlacement = 'top' | 'bottom' | 'left' | 'right' | 'center'

export interface TourStep {
  id: string
  titleKey: string
  bodyKey: string
  /** Value of data-tour attribute on the target element */
  target?: string
  placement?: TourPlacement
  /** Navigate here when this step becomes active */
  route?: string | ((workspaceId: string | null) => string)
}

export const TOUR_STORAGE_COMPLETED = 'quenyx.tour.completed'
export const TOUR_STORAGE_SKIPPED = 'quenyx.tour.skipped'

export const tourSteps: TourStep[] = [
  {
    id: 'welcome',
    titleKey: 'tour.step1.title',
    bodyKey: 'tour.step1.body',
    placement: 'center',
    route: '/dashboard',
  },
  {
    id: 'navigation',
    titleKey: 'tour.step2.title',
    bodyKey: 'tour.step2.body',
    target: 'tour-nav',
    placement: 'right',
    route: '/dashboard',
  },
  {
    id: 'workspace',
    titleKey: 'tour.step3.title',
    bodyKey: 'tour.step3.body',
    target: 'tour-workspace',
    placement: 'bottom',
    route: '/dashboard',
  },
  {
    id: 'language',
    titleKey: 'tour.step4.title',
    bodyKey: 'tour.step4.body',
    target: 'tour-language',
    placement: 'bottom',
    route: '/dashboard',
  },
  {
    id: 'ai-agent',
    titleKey: 'tour.step5.title',
    bodyKey: 'tour.step5.body',
    target: 'tour-ai-agent',
    placement: 'bottom',
    route: '/dashboard',
  },
  {
    id: 'performance',
    titleKey: 'tour.step6.title',
    bodyKey: 'tour.step6.body',
    target: 'tour-observe-content',
    placement: 'top',
    route: (workspaceId) =>
      workspaceId
        ? `/app/workspaces/${workspaceId}/observe/performance-analytics`
        : '/dashboard',
  },
  {
    id: 'alert-management',
    titleKey: 'tour.step7.title',
    bodyKey: 'tour.step7.body',
    target: 'tour-observe-content',
    placement: 'top',
    route: (workspaceId) =>
      workspaceId
        ? `/app/workspaces/${workspaceId}/observe/alert-management`
        : '/dashboard',
  },
  {
    id: 'alert-rules',
    titleKey: 'tour.step8.title',
    bodyKey: 'tour.step8.body',
    target: 'tour-observe-content',
    placement: 'top',
    route: (workspaceId) =>
      workspaceId
        ? `/app/workspaces/${workspaceId}/observe/alert-management`
        : '/integrations',
  },
  {
    id: 'onboard',
    titleKey: 'tour.step9.title',
    bodyKey: 'tour.step9.body',
    target: 'tour-integrations',
    placement: 'center',
    route: '/integrations',
  },
]
