# Service Desk Guide (QynSupport)

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

## What QynSupport is

**QynSupport** is the enterprise **Service Desk**. Sprint 24 adds **Ticket Intelligence**: evidence-based
AI triage that suggests category, priority, impact, assignee, and SLA, and surfaces related incidents,
assets, and runbooks. Suggestions are **editable and never auto-applied** — operator-confirmed fields are
always authoritative.

## Tickets

Tickets (`tickets` table) are workspace-scoped and UUID-addressed, with a human reference (`TCK-XXXXXX`).
Cross-module links to incidents and assets are stored as deterministic UUID soft-references (no module
branching). Lifecycle: `open → in_progress → pending → resolved → closed`.

## Ticket Intelligence (evidence-based)

`TicketIntelligenceService` computes a deterministic suggestion set from the ticket content and the
workspace's real history, then narrates the rationale through the shared `ModuleAiNarrator`:

- **Category** — inferred from ticket text (access/network/hardware/software/security/incident/request).
- **Priority** — escalated deterministically on signals like *outage*, *down*, *breach*, *urgent*.
- **Impact** — derived from priority (org/service/team/individual).
- **Suggested SLA** — from the configurable per-priority SLA matrix (`config/knowledge.php`).
- **Suggested assignee** — the member who resolved the most tickets in the suggested category; reports
  **"insufficient evidence"** honestly when there is no history (never invents a name).
- **Related incidents / runbooks** — via Enterprise Search over real rows.

The AI rationale is generated from this evidence only; with AI disabled the mock provider answers
(flagged) while the evidence stays real.

## Collaboration

Every ticket reuses the shared **Collaboration Platform** (comments, mentions, watchers, assignees) — see
the Collaboration Guide. There is no ticket-specific comment system.

## API (UUID-only, `workspace` required)

Base: `/api/qynsupport` (Sanctum + `throttle:ai-workspace`). Reads/writes require `accessAi`; AI surfaces
require `can_use_ai`.

| Method | Endpoint | Purpose |
|---|---|---|
| GET/POST | `/tickets` | List / create tickets |
| GET/PUT | `/tickets/{uuid}` | Read / update a ticket |
| POST | `/tickets/{uuid}/intelligence/analyze` | Evidence-based triage suggestions + AI rationale |
| POST | `/tickets/{uuid}/intelligence/copilot` | Ticket copilot (grounded conversation) |

## Guarantees

Evidence-based · editable · never auto-applied · honest "insufficient evidence" · workspace isolation ·
RBAC · UUID-only · audited · EN/AR.
