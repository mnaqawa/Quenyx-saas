import { Routes, Route, Navigate, useLocation } from 'react-router-dom'
import { lazy, Suspense, useEffect } from 'react'
import AppLayout from './layouts/AppLayout'
import Dashboard from './pages/Dashboard'
import Subscriptions from './pages/Subscriptions'
import Integrations from './pages/Integrations'
import GettingStarted from './pages/GettingStarted'
import HelpCenter from './pages/HelpCenter'
import DocsIndex from './pages/docs/DocsIndex'
import DocsViewer from './pages/docs/DocsViewer'
import Profile from './pages/Profile'
import WorkspacesPage from './pages/WorkspacesPage'
import WorkspaceDetailsPage from './pages/WorkspaceDetailsPage'
import WorkspaceAccessSettings from './pages/WorkspaceAccessSettings'
import WorkspaceMembers from './pages/WorkspaceMembers'
import Login from './pages/Login'
import Register from './pages/Register'
import InviteAcceptance from './pages/InviteAcceptance'
import ProtectedRoute from './components/ProtectedRoute'
import WorkspaceGuard from './components/WorkspaceGuard'
import { WorkspaceRouteSync } from './components/WorkspaceRouteSync'
import ObserveLayout from './layouts/ObserveLayout'
import ObserveRemovedRouteRedirect from './components/observe/ObserveRemovedRouteRedirect'
import { ObservePageSkeleton } from './components/observe/ObservePageSkeleton'
import ComingSoon from './pages/ComingSoon'
import { routesByModule } from './constants/platformRegistry'
import { validateRegistryInDevelopment } from './constants/registrySanity'

const BillingPage = lazy(() => import('./pages/Billing'))

/**
 * v1.0.0 — backward-compatible alias. "AI Workspace" is surfaced as "Quenyx AI"; the preferred
 * /quenyx-ai/* path redirects (preserving sub-path + query) to the canonical /ai-workspace/* routes
 * so existing links keep working and the new branded URL also resolves.
 */
function QuenyxAiRedirect() {
  const location = useLocation()
  const target = `${location.pathname.replace(/^\/quenyx-ai/, '/ai-workspace')}${location.search}`
  return <Navigate to={target} replace />
}

// Sprint 20 — Unified AI Workspace (platform-level)
const AiWorkspaceLayout = lazy(() => import('./layouts/AiWorkspaceLayout'))
const AiOverview = lazy(() => import('./pages/ai/AiOverview'))
const AiChat = lazy(() => import('./pages/ai/AiChat'))
const AiConversations = lazy(() => import('./pages/ai/AiConversations'))
const AiConversationDetail = lazy(() => import('./pages/ai/AiConversationDetail'))
const AiHistory = lazy(() => import('./pages/ai/AiHistory'))
const AiActivity = lazy(() => import('./pages/ai/AiActivity'))
const AiMemory = lazy(() => import('./pages/ai/AiMemory'))
const AiPromptTemplates = lazy(() => import('./pages/ai/AiPromptTemplates'))
const AiSkills = lazy(() => import('./pages/ai/AiSkills'))
const AiCapabilities = lazy(() => import('./pages/ai/AiCapabilities'))
const AiUsage = lazy(() => import('./pages/ai/AiUsage'))
const AiCosts = lazy(() => import('./pages/ai/AiCosts'))
const AiProviders = lazy(() => import('./pages/ai/AiProviders'))
const AiPermissions = lazy(() => import('./pages/ai/AiPermissions'))
const AiAdministration = lazy(() => import('./pages/ai/AiAdministration'))
const AiNotifications = lazy(() => import('./pages/ai/AiNotifications'))
const Overview = lazy(() => import('./pages/observe/Overview'))
const RealTimeMonitoring = lazy(() => import('./pages/observe/RealTimeMonitoring'))
const InfrastructureMap = lazy(() => import('./pages/observe/InfrastructureMap'))
const PerformanceAnalytics = lazy(() => import('./pages/observe/PerformanceAnalytics'))
const CapacityPlanning = lazy(() => import('./pages/observe/CapacityPlanning'))
const AlertManagement = lazy(() => import('./pages/observe/AlertManagement'))
const Services = lazy(() => import('./pages/observe/Services'))
const Targets = lazy(() => import('./pages/observe/Targets'))
const OperationsIntelligence = lazy(() => import('./pages/observe/OperationsIntelligence'))
const AssetIntelligence = lazy(() => import('./pages/asset/AssetIntelligence'))
const AutomationDashboard = lazy(() => import('./pages/automation/AutomationDashboard'))
const IncidentWorkspace = lazy(() => import('./pages/incident/IncidentWorkspace'))
const KnowledgeCenter = lazy(() => import('./pages/knowledge/KnowledgeCenter'))
const EnterpriseSearch = lazy(() => import('./pages/knowledge/EnterpriseSearch'))
const GlobalTimeline = lazy(() => import('./pages/knowledge/GlobalTimeline'))
const ServiceDesk = lazy(() => import('./pages/support/ServiceDesk'))
const NotificationCenter = lazy(() => import('./pages/notify/NotificationCenter'))
// Sprint 25 — Enterprise Intelligence Platform v1.0
const OperatorConsole = lazy(() => import('./pages/qynva/OperatorConsole'))
const ExecutiveIntelligence = lazy(() => import('./pages/qynva/ExecutiveIntelligence'))
const EnterpriseAnalytics = lazy(() => import('./pages/qynva/EnterpriseAnalytics'))
const PlatformHealth = lazy(() => import('./pages/qynva/PlatformHealth'))
const CostIntelligence = lazy(() => import('./pages/qynbalance/CostIntelligence'))

function ObserveSuspense({ children }: { children: React.ReactNode }) {
  return <Suspense fallback={<ObservePageSkeleton />}>{children}</Suspense>
}

function App() {
  useEffect(() => {
    validateRegistryInDevelopment()
  }, [])

  const observeRoutes = routesByModule.qynsight || []

  return (
    <>
      <WorkspaceRouteSync />
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route path="/register" element={<Register />} />
      <Route path="/invites/accept" element={<InviteAcceptance />} />
      <Route element={<ProtectedRoute />}>
        <Route element={<WorkspaceGuard />}>
          <Route path="/" element={<AppLayout />}>
            <Route path="dashboard" element={<Dashboard />} />
            <Route index element={<Dashboard />} />
            <Route path="app/workspaces" element={<WorkspacesPage />} />
            <Route path="app/workspaces/:id" element={<WorkspaceDetailsPage />} />
            <Route path="app/projects" element={<Navigate to="/app/workspaces" replace />} />
            <Route path="app/projects/:id" element={<WorkspaceDetailsPage />} />
            <Route path="subscriptions" element={<Subscriptions />} />
            <Route
              path="app/workspaces/:id/qyncore/billing"
              element={
                <Suspense fallback={<ObservePageSkeleton />}>
                  <BillingPage />
                </Suspense>
              }
            />
            <Route path="settings/access" element={<WorkspaceAccessSettings />} />
            <Route path="settings/members" element={<WorkspaceMembers />} />
            <Route path="integrations" element={<Integrations />} />
            <Route
              path="ai-workspace"
              element={
                <Suspense fallback={<ObservePageSkeleton />}>
                  <AiWorkspaceLayout />
                </Suspense>
              }
            >
              <Route index element={<Navigate to="overview" replace />} />
              <Route path="overview" element={<ObserveSuspense><AiOverview /></ObserveSuspense>} />
              <Route path="chat" element={<ObserveSuspense><AiChat /></ObserveSuspense>} />
              <Route path="chat/:uuid" element={<ObserveSuspense><AiChat /></ObserveSuspense>} />
              <Route path="conversations" element={<ObserveSuspense><AiConversations /></ObserveSuspense>} />
              <Route path="conversations/:uuid" element={<ObserveSuspense><AiConversationDetail /></ObserveSuspense>} />
              <Route path="history" element={<ObserveSuspense><AiHistory /></ObserveSuspense>} />
              <Route path="activity" element={<ObserveSuspense><AiActivity /></ObserveSuspense>} />
              <Route path="memory" element={<ObserveSuspense><AiMemory /></ObserveSuspense>} />
              <Route path="prompt-templates" element={<ObserveSuspense><AiPromptTemplates /></ObserveSuspense>} />
              <Route path="skills" element={<ObserveSuspense><AiSkills /></ObserveSuspense>} />
              <Route path="capabilities" element={<ObserveSuspense><AiCapabilities /></ObserveSuspense>} />
              <Route path="usage" element={<ObserveSuspense><AiUsage /></ObserveSuspense>} />
              <Route path="costs" element={<ObserveSuspense><AiCosts /></ObserveSuspense>} />
              <Route path="providers" element={<ObserveSuspense><AiProviders /></ObserveSuspense>} />
              <Route path="permissions" element={<ObserveSuspense><AiPermissions /></ObserveSuspense>} />
              <Route path="administration" element={<ObserveSuspense><AiAdministration /></ObserveSuspense>} />
              <Route path="notifications" element={<ObserveSuspense><AiNotifications /></ObserveSuspense>} />
            </Route>
            {/* v1.0.0 — preferred branded alias; redirects to the canonical /ai-workspace/* routes. */}
            <Route path="quenyx-ai/*" element={<QuenyxAiRedirect />} />
            <Route path="getting-started" element={<GettingStarted />} />
            <Route path="help-center" element={<HelpCenter />} />
            <Route path="help" element={<Navigate to="/help-center" replace />} />
            <Route path="docs" element={<DocsIndex />} />
            <Route path="docs/:slug" element={<DocsViewer />} />
            <Route path="profile" element={<Profile />} />
            <Route path="app/workspaces/:id/observe" element={<ObserveLayout />}>
              {observeRoutes.find((r) => r.key === 'overview') && (
                <Route
                  path="overview"
                  element={
                    <ObserveSuspense>
                      <Overview />
                    </ObserveSuspense>
                  }
                />
              )}
              {observeRoutes.find((r) => r.key === 'operations-intelligence') && (
                <Route
                  path="operations-intelligence"
                  element={
                    <ObserveSuspense>
                      <OperationsIntelligence />
                    </ObserveSuspense>
                  }
                />
              )}
              {observeRoutes.find((r) => r.key === 'real-time-monitoring') && (
                <Route
                  path="real-time-monitoring"
                  element={
                    <ObserveSuspense>
                      <RealTimeMonitoring />
                    </ObserveSuspense>
                  }
                />
              )}
              {observeRoutes.find((r) => r.key === 'infrastructure-map') && (
                <Route
                  path="infrastructure-map"
                  element={
                    <ObserveSuspense>
                      <InfrastructureMap />
                    </ObserveSuspense>
                  }
                />
              )}
              {observeRoutes.find((r) => r.key === 'performance-analytics') && (
                <Route
                  path="performance-analytics"
                  element={
                    <ObserveSuspense>
                      <PerformanceAnalytics />
                    </ObserveSuspense>
                  }
                />
              )}
              {observeRoutes.find((r) => r.key === 'capacity-planning') && (
                <Route
                  path="capacity-planning"
                  element={
                    <ObserveSuspense>
                      <CapacityPlanning />
                    </ObserveSuspense>
                  }
                />
              )}
              {observeRoutes.find((r) => r.key === 'alert-management') && (
                <Route
                  path="alert-management"
                  element={
                    <ObserveSuspense>
                      <AlertManagement />
                    </ObserveSuspense>
                  }
                />
              )}
              <Route path="data-sources" element={<ObserveRemovedRouteRedirect />} />
              <Route path="reports" element={<ObserveRemovedRouteRedirect />} />
              <Route path="instance-management" element={<ObserveRemovedRouteRedirect />} />
              <Route path="instances" element={<ObserveRemovedRouteRedirect />} />
              {observeRoutes.find((r) => r.key === 'services') && (
                <Route
                  path="services"
                  element={
                    <ObserveSuspense>
                      <Services />
                    </ObserveSuspense>
                  }
                />
              )}
              {observeRoutes.find((r) => r.key === 'targets') && (
                <Route
                  path="targets"
                  element={
                    <ObserveSuspense>
                      <Targets />
                    </ObserveSuspense>
                  }
                />
              )}
              <Route index element={<Navigate to="overview" replace />} />
            </Route>
            {/* Sprint 22 — QynAsset Asset Intelligence dashboard (second production AI adapter). */}
            <Route
              path="app/workspaces/:id/qynasset/intelligence"
              element={
                <ObserveSuspense>
                  <AssetIntelligence />
                </ObserveSuspense>
              }
            />
            {/* Sprint 23 — QynRun Automation Platform + QynReact Incident Workspace. */}
            <Route
              path="app/workspaces/:id/qynrun/automation"
              element={
                <ObserveSuspense>
                  <AutomationDashboard />
                </ObserveSuspense>
              }
            />
            <Route
              path="app/workspaces/:id/qynreact/incidents"
              element={
                <ObserveSuspense>
                  <IncidentWorkspace />
                </ObserveSuspense>
              }
            />
            {/* Sprint 24 — Enterprise Knowledge & Collaboration Platform. */}
            <Route
              path="app/workspaces/:id/qynknow/knowledge"
              element={
                <ObserveSuspense>
                  <KnowledgeCenter />
                </ObserveSuspense>
              }
            />
            <Route
              path="app/workspaces/:id/qynknow/search"
              element={
                <ObserveSuspense>
                  <EnterpriseSearch />
                </ObserveSuspense>
              }
            />
            <Route
              path="app/workspaces/:id/qynknow/timeline"
              element={
                <ObserveSuspense>
                  <GlobalTimeline />
                </ObserveSuspense>
              }
            />
            <Route
              path="app/workspaces/:id/qynsupport/tickets"
              element={
                <ObserveSuspense>
                  <ServiceDesk />
                </ObserveSuspense>
              }
            />
            <Route
              path="app/workspaces/:id/qynnotify/notifications"
              element={
                <ObserveSuspense>
                  <NotificationCenter />
                </ObserveSuspense>
              }
            />
            {/* Sprint 25 — QynVA Enterprise AI Operator + Enterprise Intelligence (Executive, Analytics, Health). */}
            <Route path="app/workspaces/:id/qynva/operator" element={<ObserveSuspense><OperatorConsole /></ObserveSuspense>} />
            <Route path="app/workspaces/:id/qynva/executive" element={<ObserveSuspense><ExecutiveIntelligence /></ObserveSuspense>} />
            <Route path="app/workspaces/:id/qynva/analytics" element={<ObserveSuspense><EnterpriseAnalytics /></ObserveSuspense>} />
            <Route path="app/workspaces/:id/qynva/health" element={<ObserveSuspense><PlatformHealth /></ObserveSuspense>} />
            {/* Sprint 25 — QynBalance Enterprise Cost Intelligence. */}
            <Route path="app/workspaces/:id/qynbalance/cost" element={<ObserveSuspense><CostIntelligence /></ObserveSuspense>} />
            <Route path="app/workspaces/:id/modules/:moduleKey" element={<ComingSoon />} />
          </Route>
        </Route>
      </Route>
    </Routes>
    </>
  )
}

export default App
