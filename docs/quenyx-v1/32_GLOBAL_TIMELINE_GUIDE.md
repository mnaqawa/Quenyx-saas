# Global Timeline Guide

> **Quenyx vOPS HUB — Document Metadata**
>
> | Field | Value |
> |---|---|
> | Document Version | 1.0 |
> | Software Version | v1.0.0 RC1 |
> | Applies To | Quenyx vOPS HUB v1.0.0 RC1 — Sprint 24 |
> | Classification | Internal |
> | Owner | Platform Engineering |
> | Status | Released |
> | Document Type | Platform capability guide |

## What the Global Timeline is

A **platform-wide, chronological read-model** (Sprint 24) that unifies events from every module into one
ordered stream so operators can see what happened across the HUB, in time order, in one place. It
**aggregates existing rows** — it never duplicates or fabricates events, and it writes nothing.

## Sources

`GlobalTimelineService` reads from real tables and normalizes each into a typed timeline event
(`{ type, uuid, title, summary, occurred_at, source }`), sorted descending and bounded by a configurable
limit:

- **Incidents** — opened/updated
- **Automation** — workflow executions / runbook runs
- **Tickets** — created/updated
- **Notifications** — ingested signals
- **Knowledge** — document created/updated

Each source is toggleable in `config/knowledge.php → timeline.sources`, and the maximum number of events
is bounded by `timeline.limit`. Because it is a pure read-model, enabling a new source is additive and
never affects writers.

## API (UUID-only, `workspace` required)

Base: `/api/qynknow` (Sanctum + `throttle:ai-workspace`). Requires `accessAi`.

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/timeline` | Unified chronological events across modules |

Optional query params: `limit` (bounded by config), `sources[]` (subset of configured sources).

## UI

The **Global Timeline** page renders the unified stream with per-type icons and source filters, reusing
the standard workspace layout and the `useAiWorkspaceUuid()` context. It is read-only.

## Guarantees

Read-model only (no duplication, no fabrication, no writes) · real rows only · workspace isolation ·
RBAC · UUID-only · EN/AR.
