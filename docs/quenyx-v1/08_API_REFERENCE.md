# 08 — API Reference

> **Quenyx vOPS HUB — Document Metadata**
>
> | Field | Value |
> |---|---|
> | Document Version | 2.0 |
> | Software Version | v1.0.0 RC1 |
> | Applies To | Quenyx vOPS HUB v1.0.0 RC1 |
> | Classification | Internal |
> | Owner | Platform Engineering |
> | Status | Released |
> | Last Updated | 2026-06-29 |
> | Document Type | API reference |
>
> **Revision History**
>
> | Version | Date | Notes |
> |---|---|---|
> | 1.0 | 2026 | Initial v1 pack (through Sprint 19). |
> | 2.0 | 2026-06-29 | Aligned to v1.0.0 RC1; includes Unified AI Workspace (Sprint 20) endpoints. |

**Audience:** Engineers, integrators.
**Source:** Derived from `php artisan route:list` (261 routes) and the `routes/*.php` files at
Sprint 19. **No endpoints are invented.** Regenerate this doc when routes change.

> **`projects` vs `workspaces`.** Most tenant routes exist under **both** `/api/projects/{project}/…`
> and `/api/workspaces/{project}/…` — they are **aliases to the same controllers**. Below, paths are
> shown with `/workspaces/{project}` (the canonical form); swap `workspaces` → `projects` for the
> alias.

---

## 1. Authentication

- **Scheme:** Laravel **Sanctum** bearer tokens.
- **Obtain a token:** `POST /api/auth/login` (`POST /api/auth/register` to create an account).
- **Use it:** `Authorization: Bearer <token>` on every authenticated route.
- **Identity:** `GET /api/auth/me`; logout `POST /api/auth/logout`.
- **Public (no user auth):** `GET /api/health`, agent ingestion endpoints (token/secret auth).

## 2. Common headers

```
Authorization: Bearer <token>
Accept: application/json
Content-Type: application/json     # for POST/PUT/PATCH
```

## 3. Error format

Standard Laravel JSON errors:

```json
{ "message": "Unauthenticated." }                       // 401
{ "message": "This action is unauthorized." }           // 403
{ "message": "Not Found" }                               // 404
{ "message": "The given data was invalid.",              // 422
  "errors": { "field": ["validation message"] } }
{ "message": "Too Many Attempts." }                      // 429 (throttled routes)
```

## 4. Workspace / project routes

| Method | Path | Controller |
|---|---|---|
| GET/POST | `/api/projects` · `/api/workspaces` | `ProjectController@index/store` |
| GET/PUT/DELETE | `/api/workspaces/{project}` | `ProjectController@show/update/destroy` |
| GET | `/api/workspaces/{project}/entitlements` | `ProjectSubscriptionController@entitlements` |
| GET/PUT | `/api/workspaces/{project}/subscription` | `ProjectSubscriptionController@show/update` |
| GET | `/api/workspaces/{project}/modules` · `/modules/access` | `ProjectModuleController` |
| PUT | `/api/workspaces/{project}/modules/{moduleKey}/override` | `ProjectModuleOverrideController@update` |
| GET..DELETE | `/api/workspaces/{project}/memberships…` | `ProjectMembershipController` |
| GET | `/api/workspaces/{project}/audit-logs` | `AuditLogController@index` |
| GET..PUT | `/api/workspaces/{project}/integrations…` | `ProjectIntegrationController` |
| GET..POST | `/api/workspaces/{project}/billing/…` | `BillingController` |
| GET | `/api/dashboard`, `/api/modules`, `/api/plans` | platform meta |

## 5. QynSight APIs (summary) — `project.module:qynsight`

Prefix: `/api/workspaces/{project}/observe/…`

- **Summary / instances:** `summary`, `instances`, `instances/summary`.
- **Services / checks:** `services`, `service-definitions`, `run-checks`.
- **Performance:** `performance/metrics`, `real-time/metrics`, `real-time/system-info`,
  `real-time/thresholds`.
- **Capacity:** `capacity-planning`, `capacity-planning/export`, `capacity/metrics`.
- **Alerts:** `alerts/rules` (GET/POST/PUT/DELETE/`toggle`), `alerts/summary`, `alerts/history`,
  `alerts/events/{event}/acknowledge`, `alerts/channels`, `monitoring-profile` (GET/PUT).
- **Infrastructure:** `infrastructure/topology`, `infrastructure/connections`,
  `infrastructure/port-scans` (GET/POST `run`).
- **Reports / data sources:** `reports`, `reports/summary`, `data-sources`, `data-sources/summary`.
- **Targets / hosts:** `targets` (GET/PUT), `targets/validate`, `targets/{hostId}/port-scan`.
- **Agents:** `/api/workspaces/{project}/agents…` (list, metadata, enrollment tokens, revoke,
  destroy).

**Agent ingestion (token‑auth, no user):** `GET /api/agents/download/{platform}`,
`POST /api/agents/register`, `POST /api/agents/{agent}/heartbeat|metrics|inventory`.

## 6. Compliance corpus APIs

Global (read): `/api/compliance/corpus/frameworks…`
Workspace (entitled, `project.qynshield`): `/api/workspaces/{project}/compliance/corpus/frameworks…`

| Path (suffix under `…/corpus`) | Returns |
|---|---|
| `frameworks` | frameworks list |
| `frameworks/{frameworkKey}/releases` | releases |
| `frameworks/{frameworkKey}/releases/{releaseCode}/summary` | release summary |
| `…/domains` · `…/domains/{domainCode}` | domains |
| `…/controls/{controlCode}` | control detail |
| `…/search` | corpus search |

## 7. AI context APIs (consumption contract, no AI execution)

`/api/workspaces/{project}/compliance/ai-context/frameworks/{frameworkKey}/releases/{releaseCode}/…`
→ `summary`, `domains/{domainCode}`, `controls/{controlCode}`, `search`. Returns deterministic
AI‑ready context; **makes no model call**.

## 8. Graph APIs

`/api/workspaces/{project}/compliance/graph/frameworks/{frameworkKey}/releases/{releaseCode}` and
`…/domains/{domainCode}`, `…/controls/{controlCode}`, `…/requirements/{requirementCode}`.

## 9. Mapping APIs

`/api/workspaces/{project}/compliance/mappings/…` → `objectives`, `objectives/{objectiveCode}`,
`controls/{controlCode}`, `frameworks/compare`, `frameworks/{frameworkKey}/coverage`.

## 10. Evidence APIs

`/api/workspaces/{project}/compliance/evidence/…` → `types`, `statuses`, `POST context`.

## 11. Gap APIs

`/api/workspaces/{project}/compliance/gap/…` → `summary`, `domains`, `controls/{controlCode}`,
`requirements/{requirementCode}`, `POST context`.

## 12. Recommendation APIs

`/api/workspaces/{project}/compliance/recommendations/…` → `summary`, `controls/{controlCode}`,
`requirements/{requirementCode}`, `POST generate`, `POST context`.

## 13. Copilot APIs — throttle `compliance-copilot`

- `POST /api/workspaces/{project}/compliance/copilot/message`
- `GET /api/workspaces/{project}/compliance/copilot/conversations`
- `GET /api/workspaces/{project}/compliance/copilot/conversations/{conversationUuid}`
- `POST /api/workspaces/{project}/compliance/copilot/conversations/{conversationUuid}/messages`

**Example (mock mode):**

```http
POST /api/workspaces/{project}/compliance/copilot/message
{ "message": "Why is requirement 1-1-1 non-compliant?" }
```

```json
{
  "answer": "…cited explanation…",
  "citations": [ { "type": "control", "code": "1-1-1", "source_document_id": "<uuid>" } ],
  "mode": "mock"
}
```

(With `AI_COPILOT_DEMO_MODE=true`, a `demo` block with reasoning trace/citations is added. With
`AI_COPILOT_RAG_ENABLED=true` + `RAG_ENABLED=true`, a `rag_context` block may be added.)

## 14. Retrieval / RAG APIs

- **Retrieval (deterministic):** `POST /api/workspaces/{project}/compliance/retrieval/query`
- **RAG (feature‑flagged, returns RAG context only):** `POST /api/workspaces/{project}/compliance/rag/query` — throttle `compliance-rag`.

```http
POST /api/workspaces/{project}/compliance/rag/query
{ "query": "access control policy" }
```

Returns a bounded, **cited** context package. With RAG disabled, falls back to deterministic
retrieval context.

## 15. Executive APIs (read‑only) — throttle `compliance-executive`

`/api/workspaces/{project}/compliance/executive/…` → `dashboard`, `scorecard`, `timeline`,
`explainability`, `platform`. All derived from **real engine data**; no fabricated metrics.

## 16. Platform AI capability endpoint

`GET /api/ai/platform/capabilities` → modules (adapters), skills, providers, reasoning/retrieval/RAG
status, supported contexts, and HUB‑wide `module_catalog` (production/reserved/planned).

## 17. Unified AI Workspace (Sprint 20)

Platform‑level AI surface beside Dashboard / Workspaces / Integrations. All routes are flat under
`/api/ai/*`, Sanctum‑protected, throttled by `ai-workspace`, and **scoped by a required `workspace`
UUID** (query string for reads/deletes, request body for writes) — never a numeric id. Every resource
id returned is a UUID. Authorization: `ProjectPolicy::accessAi` (any member) for reads,
`administerAi` (owner/admin) for provider/permission changes, plus a fine‑grained capability check
(`can_use_ai`, `can_manage_templates`, `can_manage_providers`, `can_view_costs`, `can_administer`).

| Method | Endpoint | Capability | Notes |
| --- | --- | --- | --- |
| GET | `/api/ai/workspace/summary` | access | counts, tokens, flags + caller permissions |
| GET | `/api/ai/conversations` | access | list (metadata only) |
| POST | `/api/ai/conversations` | use_ai | start a conversation |
| GET | `/api/ai/conversations/{uuid}` | access | conversation + messages |
| POST | `/api/ai/conversations/{uuid}/messages` | use_ai | runs the shared AI runtime (mock until AI enabled) |
| GET | `/api/ai/activity` | access | AI audit timeline |
| GET | `/api/ai/notifications` | access | governance events |
| GET | `/api/ai/usage` | view_costs | token usage totals/by‑provider/daily |
| GET | `/api/ai/costs` | view_costs | tokens × configured pricing; `pricing_configured` flag |
| GET | `/api/ai/skills` | access | dynamic skill catalog (Sprint 19) |
| GET | `/api/ai/capabilities` | access | full platform capability catalog |
| GET | `/api/ai/prompt-templates` | access | list |
| POST | `/api/ai/prompt-templates` | manage_templates | create |
| PUT | `/api/ai/prompt-templates/{uuid}` | manage_templates | update |
| DELETE | `/api/ai/prompt-templates/{uuid}` | manage_templates | delete |
| GET | `/api/ai/providers` | access | provider prefs (secrets never returned) |
| PUT | `/api/ai/providers/{uuid}/settings` | manage_providers + admin | encrypted secret write‑only |
| GET | `/api/ai/permissions` | admin | effective per‑role matrix |
| PUT | `/api/ai/permissions` | admin | upsert per‑role overrides |

Cost tracking is **derived** from real `ai_conversations` token counts and an optional
`config('ai.workspace.pricing')` table; with no pricing configured, responses carry token totals and
`pricing_configured=false` with **no fabricated currency**. Conversation message content is stored
only when `ai.feature_flags.prompt_logging` is enabled (privacy‑preserving default). Provider settings
addressing uses a deterministic UUIDv5 (workspace + provider key) so providers are UUID‑addressable
even before a settings row exists. Provider id `uuid` is also added (additively) to the workspace list
(`GET /api/workspaces`) and `ProjectResource` so the UI can pass the workspace UUID.

## 18. Notes on examples

Example request/response shapes are representative of the controllers' contracts. For exact current
fields, call the endpoint against a seeded workspace, or read the corresponding controller/resource
in `app/Http/Controllers/**`. Do not assume undocumented fields.
