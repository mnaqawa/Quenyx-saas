---
name: react-typescript-vite-expert
description: Expert guidance for React + TypeScript + Vite apps: strongly typed APIs, hooks, state synchronization, polling strategies, rendering safety, error boundaries, and UI reliability. Never masks backend failures; surfaces actionable errors. Use when building or fixing React/TSX components, API integration, hooks, polling, error handling, or when the user mentions Vite, React state, or frontend reliability.
---

# React + TypeScript + Vite Expert

Apply this skill when working on the frontend SPA: typed API contracts, hooks, state sync, polling, safe rendering, error boundaries, and reliable UI. **Never hide backend failures in the UI**; show clear, actionable errors.

## Strongly Typed APIs

- **Contract-first**: Types must mirror backend/Gateway responses. Define shared types (e.g. in `types/`) and use them in services and components.
- **No `any` for API data**: Use explicit interfaces for request/response bodies. Use generics on fetch wrappers: `apiClient.get<Workspace[]>(...)`.
- **Runtime validation**: For external or unstable APIs, validate at the boundary (e.g. Zod) and map to internal types; log and surface validation failures instead of rendering bad data.
- **Error response typing**: Type error payloads (e.g. `{ message: string; code?: string }`) so the UI can show specific messages and actions.

## Hooks

- **Single responsibility**: One hook per concern (data fetch, subscription, polling). Compose in components.
- **Return a tuple**: `[data, loading, error, refetch]` or similar so callers can branch on loading/error and retry.
- **Stale closure safety**: For polling or timers, use refs for latest values/callbacks or ensure dependency arrays include all used values.
- **Cleanup**: Return a cleanup function from `useEffect` (abort controllers, clear intervals/timeouts, unsubscribe) to avoid leaks and updates after unmount.

## State Synchronization

- **Single source of truth**: Prefer server state as source; derive local UI state where needed. Avoid duplicating server state in multiple places.
- **Optimistic updates**: Only for non-critical paths; revert and show error if the request fails. Do not leave the UI in a success state when the backend failed.
- **Cross-tab / multi-instance**: If relevant, use storage events or a shared context; keep server as authority and re-fetch or invalidate when needed.

## Polling Strategies

- **Exponential backoff or capped interval**: Avoid hammering the backend; use a reasonable interval (e.g. 5–30s) or backoff on repeated errors.
- **Pause on hidden tab**: Use `document.visibilityState` / `visibilitychange` to pause polling when the tab is hidden; resume when visible (and optionally refresh once).
- **Stop on unmount**: Clear the timer/interval and abort in-flight requests in the effect cleanup.
- **Show freshness**: Display “Last updated at” or a subtle indicator so users know data may be delayed; consider a manual “Refresh” action.

## Rendering Safety

- **Guard before render**: Check `loading` and `error` before rendering list/detail content. Render skeletons or placeholders for loading; dedicated blocks for errors.
- **Null/undefined**: Use optional chaining and nullish coalescing; avoid rendering when required data is missing (e.g. `if (!workspace) return null` or early return).
- **Lists**: Key by stable IDs from the backend, not array index. Avoid keys that can change (e.g. random or derived from unstable fields).

## Error Boundaries

- **Place boundaries at route or section level**: Wrap major sections (e.g. dashboard, settings) so one failing component does not blank the whole app.
- **Log and report**: In `componentDidCatch` / static `getDerivedStateFromError`, log the error and optionally send to monitoring; do not swallow silently.
- **Fallback UI**: Show a clear message and a recovery action (e.g. “Something went wrong” + “Try again” or “Go to dashboard”). Do not show a generic “Error” with no next step.
- **Async/server errors**: Prefer handling in the data layer (hooks/services) and showing inline errors. Use boundaries for unexpected render-time crashes.

## UI Reliability and Backend Failures

- **Never mask backend failures**: Do not show “Success” or update the UI as if the request succeeded when the API returned 4xx/5xx or a business error. Show the real error state and message.
- **Actionable errors**: Display user-facing messages that suggest an action (e.g. “Session expired. Please log in again.” or “Could not save. Check your connection and try again.”). Include retry where appropriate.
- **Structured error state**: Keep `error: Error | null` (or a typed error shape) in state; derive message and UI from it. Avoid storing only a string if you need to branch on code or type.
- **No silent fallbacks**: Do not substitute fake or default data when the backend call failed. Prefer “Failed to load” + error message + retry over showing stale or empty data without explanation.

## Quick Checklist

- [ ] API types match backend contract; no `any` for API data
- [ ] Hooks return loading/error and support refetch/cleanup
- [ ] Polling respects visibility and unmount; does not run forever
- [ ] Loading and error states are rendered explicitly; no rendering with missing data
- [ ] Error boundaries wrap major sections; fallback is actionable
- [ ] Backend failures are visible to the user with clear, actionable messages
