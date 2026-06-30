# Enterprise Context Engine Guide

> **Quenyx vOPS HUB — Document Metadata**
>
> | Field | Value |
> |---|---|
> | Document Version | 1.0 |
> | Software Version | v1.0.0 |
> | Applies To | Quenyx vOPS HUB v1.0.0 — Sprint 25 |
> | Classification | Internal |
> | Owner | Platform Engineering |
> | Status | Released |
> | Document Type | Platform guide |

## What it is

The **Enterprise Context Engine** (`app/Services/Platform/Context/EnterpriseContextEngine.php`) builds
**one normalized AI context object** from every platform source so that every AI adapter and the QynVA
operator consume the *same* context. There is exactly one place that assembles enterprise context — no
duplicated context-building anywhere.

It is a pure **read-model**: it reuses existing deterministic services and writes nothing.

## What it assembles

| Section | Source (reused) |
|---|---|
| `workspace` | the resolved `Project` (uuid, name) |
| `user` + `permissions` | `AiWorkspaceContextResolver::effectivePermissions()` |
| `cross_module` | `CrossModuleOrchestrator::gather()` (monitoring, assets, automation, knowledge, incidents, notifications, compliance — via the AI Adapter Registry, no module branching) |
| `timeline` | `GlobalTimelineService::build()` |
| `graph` | `KnowledgeGraphService::build()` (Knowledge Graph v2) |
| `search` | `EnterpriseSearchService::search()` (only when a `query` option is supplied) |
| `summary` | deterministic digest (counts only — no fabrication) |

The Monitoring/Assets/Automation/Knowledge/Incidents/Notifications/Compliance dimensions arrive through
the cross-module gather, which iterates the AI Adapter Registry and respects workspace entitlements.

## Usage

```php
$context = app(EnterpriseContextEngine::class)->build($project, $user, [
    'query'          => 'high cpu on web tier',   // optional → includes enterprise search
    'exclude'        => ['qynva'],                 // optional → skip a module in the gather
    'include'        => ['workspace','cross_module','timeline','graph','search'], // optional subset
    'timeline_limit' => 25,
]);
```

The returned object is bounded for predictable size and is consumed by:

- **QynVA** (the Enterprise AI Operator) — reasons over the whole context.
- **Executive Intelligence** — the executive AI summary narrates a deterministic dashboard built from the
  same underlying read-models.
- Any future AI adapter — call the engine instead of hand-assembling context.

## Design rules

- **Reuse, don't duplicate** — the engine never re-queries data a module service already exposes.
- **Recursion-safe** — QynVA excludes itself from the cross-module gather; the QynVA adapter's own
  `buildContext()` is registry-introspection only (no gather), so context building cannot recurse.
- **Honest** — counts and real rows only; nothing is fabricated. The `summary` block is computed from the
  assembled sections.
- **Workspace-scoped + RBAC** — permissions are part of the context so downstream AI can respect them.
