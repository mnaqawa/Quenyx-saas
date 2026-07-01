# Sprint 26 — Enterprise UX & Product Polish (GA Release Candidate)

## Summary

Sprint 26 transforms Quenyx vOPS HUB from an engineering prototype into a polished commercial SaaS shell comparable to enterprise control planes (Azure Portal, Datadog, Grafana Cloud patterns) without rewriting modules or changing backend APIs.

## UX improvements completed

| Area | Change |
|------|--------|
| Sidebar | Reordered: Dashboard → Workspaces → Quenyx AI → Integrations; Modules section separated; Help Center replaces Help & Docs; user profile remains in footer |
| Quenyx AI nav | Horizontal scrolling tabs replaced with vertical grouped navigation (Workspace / AI / Administration) |
| Enterprise Dashboard | Cross-module cards with real API data; states: No data / Not configured / Not enabled |
| Workspace selector | Rich dropdown: name, environment, role, host count, health hint |
| KPI cards | `StatCard` icon slot with consistent 40px icon container |
| Empty states | `EmptyState` supports primary + secondary actions and variants |
| Integrations | Sectioned: Agents, Installed, Marketplace, Monitoring, Credentials |
| Breadcrumbs | Reusable clickable `Breadcrumbs` component (QynSight wired) |
| Ask Quenyx AI | Renamed from "AI Agent" in header, drawer, and key i18n strings |
| Module icons | SVG icon set per module in sidebar |
| Enterprise Health | Composite 0–100 score from QynVA executive signals when available |
| Notifications | Header bell with unread count from QynNotify API |
| Global search | Top-bar search opens command palette |
| Command palette | Ctrl/Cmd+K — modules, actions, recent routes |
| Help Center | `/help-center` page with docs, API, release notes, quick start, support |

## Design consistency

- Orange reserved for AI primary actions (`Ask Quenyx AI`)
- Sky blue for navigation selection and primary CTAs
- Shared card surface: `rounded-2xl border border-white/10 bg-[#0f151d]`
- Hover transitions on cards, sidebar links, and module tiles
- Module icons consistent in sidebar and command palette

## Accessibility

- Focus-visible outlines on breadcrumbs, command palette, workspace selector
- Escape closes command palette, notification dropdown, workspace selector
- ARIA labels on search, notifications, AI drawer, breadcrumbs
- Keyboard navigation in command palette (arrows + Enter)

## Navigation

- Help route redirects to `/help-center`
- Recent routes stored in `localStorage` for command palette
- QynSight breadcrumbs use link-based navigation

## Performance impact

- Enterprise dashboard loads module APIs in parallel per workspace
- QynSight host count reused from existing `useObserveServices` hook
- Command palette and notification bell lazy-load data on open
- No new backend endpoints; no synthetic dashboard metrics

## Files changed (primary)

### New
- `frontend/src/components/icons/ModuleIcons.tsx`
- `frontend/src/components/layout/Breadcrumbs.tsx`
- `frontend/src/components/layout/WorkspaceSelector.tsx`
- `frontend/src/components/layout/CommandPalette.tsx`
- `frontend/src/components/layout/GlobalSearchBar.tsx`
- `frontend/src/components/layout/NotificationBell.tsx`
- `frontend/src/components/dashboard/EnterpriseModuleCard.tsx`
- `frontend/src/hooks/useEnterpriseDashboard.ts`
- `frontend/src/pages/HelpCenter.tsx`
- `docs/quenyx-v1/SPRINT_26_UX_POLISH.md`

### Modified
- `frontend/src/layouts/AppLayout.tsx`
- `frontend/src/layouts/AiWorkspaceLayout.tsx`
- `frontend/src/layouts/ObserveLayout.tsx`
- `frontend/src/pages/Dashboard.tsx`
- `frontend/src/pages/Integrations.tsx`
- `frontend/src/components/observe/StatCard.tsx`
- `frontend/src/components/observe/capacity/EmptyState.tsx`
- `frontend/src/components/ai/AIAgentDrawer.tsx`
- `frontend/src/i18n/translations.ts` (EN + AR)
- `frontend/src/App.tsx`

## Validation

- TypeScript: no linter errors on modified layout/dashboard files
- No fake dashboard metrics (`/api/dashboard` synthetic series not used)
- Routes preserved; `/help` → `/help-center`
- EN + AR strings added for all new UI labels

## Remaining recommendations (v1.1)

1. Roll out module icons to all module page headers and QynVA tabs
2. Wire command palette to live Enterprise Search API results (hosts, assets, tickets)
3. Add contextual quick actions to `PageHeader` per route via registry metadata
4. Extend breadcrumbs to Automation, Knowledge, QynVA, and Integrations layouts
5. Replace legacy `/api/dashboard` synthetic metrics or deprecate endpoint
6. Add workspace region field in backend for selector display
7. Toast adoption (`useToast`) for save/error feedback across forms
8. Light theme audit for new components
9. PDF regeneration for customer/admin guides referencing Help Center and Enterprise Dashboard
