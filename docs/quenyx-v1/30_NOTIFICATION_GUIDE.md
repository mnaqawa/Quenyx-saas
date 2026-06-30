# Notification Guide (QynNotify)

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
> | Document Type | Module guide |

## What QynNotify is

**QynNotify** is intelligent **notification routing**. Sprint 24 makes it deterministic, auditable, and
honest: deduplication, correlation, urgency scoring, recipient selection, channel selection, and an
escalation path — all computed from real data. AI digests and executive summaries reuse the shared
Quenyx AI runtime. **There is no fake routing**: recipients are only ever real workspace members, and no
message is dispatched until a real transport is connected (channel selection is recorded intent).

## How ingestion works (`NotificationService::ingest`)

1. **Deduplication** — a deterministic `dedup_key = sha256(type|source|title)` collapses duplicates
   within a configurable window (`correlation_window_minutes`) into one notification, incrementing
   `dedup_count` instead of creating a new row.
2. **Correlation** — a deterministic `correlation_id` groups related signals (by source + type, or a
   caller-supplied id) for clustered triage.
3. **Urgency scoring** — `0–100` from severity weight (config), reduced by age, raised by repeats.
4. **Recipient selection** — real workspace members by role: critical/high → owners/admins; lower
   severities → all members. Falls back to the workspace owner for critical.
5. **Channel selection** — by severity (critical→sms, high→email, else in_app) from config.
6. **Escalation path** — an ordered, time-delayed list of recipients for critical/high.

Everything is audited via the shared platform audit logger.

## Notification Intelligence

`NotificationIntelligenceService` narrates real active notifications through `ModuleAiNarrator`:

- **Digest** — concise on-call digest: what is most urgent, which signals correlate, next action.
- **Executive summary** — leadership-facing posture summary.
- **Copilot** — grounded Q&A over active notifications + correlation groups.

## API (UUID-only, `workspace` required)

Base: `/api/qynnotify` (Sanctum + `throttle:ai-workspace`). Reads/writes require `accessAi`; AI surfaces
require `can_use_ai`.

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/notifications` | List notifications + correlation groups |
| POST | `/notifications` | Ingest a signal (dedup/correlate/route) |
| POST | `/notifications/{uuid}/read` | Mark read |
| POST | `/intelligence/digest` | AI digest |
| POST | `/intelligence/executive` | AI executive summary |
| POST | `/intelligence/copilot` | Notification copilot |

## Configuration (`config/knowledge.php → notifications`)

- `severity_weight` — urgency base per severity.
- `correlation_window_minutes` — deduplication/correlation window.
- `channels` — available delivery channels.

## Guarantees

Deterministic · auditable · real recipients only (no fake routing) · workspace isolation · RBAC ·
UUID-only · EN/AR.
