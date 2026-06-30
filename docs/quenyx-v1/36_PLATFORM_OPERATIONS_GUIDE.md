# Platform Operations Guide

> **Quenyx vOPS HUB — Document Metadata**
>
> | Field | Value |
> |---|---|
> | Document Version | 1.0 |
> | Software Version | v1.0.0 |
> | Applies To | Quenyx vOPS HUB v1.0.0 — Sprint 25 |
> | Classification | Internal |
> | Owner | Platform Engineering / SRE |
> | Status | Released |
> | Document Type | Operations guide |

## Purpose

How to operate the Quenyx vOPS HUB platform itself in production: the registries that make it extensible,
the Platform Health surface that watches it, and the day-2 runbook for the Sprint 25 services.

## Platform Health

`GET /api/qynva/health` (requires `administerAi`) →
`app/Services/Platform/Health/PlatformHealthService.php`. Returns an overall status plus per-area status:

| Area | What it checks |
|---|---|
| AI Platform | provider availability / mock fallback, AI Adapter Registry populated |
| Automation Platform | Automation Adapter + Action registries populated |
| Knowledge Platform | Knowledge Source Registry populated |
| Search | Enterprise Search reachable |
| Registries | AI / automation / action / knowledge registry counts |
| Provider health | configured AI provider reachability (honest when in mock mode) |
| Event Bus | subscriber count + recent event ring |
| Queues | queue connection + pending/failed jobs (honest if not inspectable) |
| Background jobs | scheduler/worker liveness signals |

Status bands: `operational`, `degraded`, `down`. Surfaced in the UI at `/qynva/health`.

## Registries (the extensibility seams)

All four follow one pattern: register one line in `AppServiceProvider::boot()`, no module branching.

| Registry | Add a… |
|---|---|
| `AiModuleAdapterRegistry` | module AI adapter (capabilities + context) |
| `AutomationAdapterRegistry` | execution adapter (SSH, REST, …) |
| `ActionRegistry` | automation action |
| `KnowledgeSourceRegistry` | knowledge source provider |
| `PlatformEventBus` | event subscriber (`subscribe()`) |

## Day-2 runbook

- **AI shows `ai_enabled: false` everywhere** → provider not configured; platform runs on the deterministic
  mock. Configure a provider in AI settings; evidence is always real regardless.
- **QynBalance shows "pricing unavailable"** → expected until `config/cost.php` rates are set via env. This
  is correct, honest behavior — not a bug.
- **A subscriber throws** → publishing is unaffected (failures are caught + logged). Inspect the log and
  `GET /api/qynva/events`.
- **Health area `degraded`/`down`** → open `/qynva/health`, read the area's reason, follow the matching
  registry/queue/provider check above.
- **Event Bus async migration** → replace the `dispatch()` body in `PlatformEventBus` with a queued job;
  publishers and subscribers need no changes.

## Security envelope (every Sprint 25 endpoint)

Workspace resolution → module entitlement → RBAC policy (`accessAi` / `can_use_ai` / `administerAi`) →
audit. UUID-only addressing and workspace isolation are enforced platform-wide.
