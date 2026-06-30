# Enterprise Knowledge Guide (QynKnow)

> **Quenyx vOPS HUB — Document Metadata**
>
> | Field | Value |
> |---|---|
> | Document Version | 1.0 |
> | Software Version | v1.0.0 |
> | Applies To | Quenyx vOPS HUB v1.0.0 — Sprint 24 |
> | Classification | Internal |
> | Owner | Platform Engineering |
> | Status | Released |
> | Document Type | Module / platform guide |

## What QynKnow is

Sprint 24 turns **QynKnow** into the **Enterprise Knowledge Platform** — a shared, reusable platform
capability (not an isolated module). It provides a registry of knowledge sources, an enterprise +
semantic search interface across every module, an Enterprise Knowledge Graph v2, a Global Timeline, and
an AI **Knowledge Assistant** that explains, summarizes, relates, and drafts — always grounded in real
indexed evidence and **never fabricating** content.

Everything reuses the existing shared platform: the **AI Adapter Registry**, the **`ModuleAiNarrator`**
runtime, the workspace/RBAC envelope, and the audit pipeline. No AI, automation, or orchestration logic
is duplicated.

## Mental model

```
Knowledge Source Registry  →  Enterprise Search  →  Knowledge Assistant (AI)
        (providers)             (real indexed data)      (explain/summarize/draft)
              \                          |                        /
               \                  Knowledge Graph v2      Global Timeline
                \                  (typed nodes/edges)   (chronological events)
                 \________________________ shared platform ____________________/
```

## Knowledge Source Registry

A dynamic registry (`App\Services\Knowledge\KnowledgeSourceRegistry`) of providers implementing the
`App\Contracts\Knowledge\KnowledgeSource` contract. Search and the Assistant consume providers **only**
through the registry — there is **no provider-specific branching**.

- **Internal Knowledge Base** (`internal`) — always operational, backed by the `knowledge_documents`
  table. Deterministic lexical relevance (title/tag/body token overlap), real rows only.
- **Planned providers** — Markdown, PDF, HTML, Git, GitHub/GitLab Wiki, Confluence, SharePoint, Google
  Drive, OneDrive, MediaWiki, Elastic/OpenSearch, Vector Store. These register so the catalog is
  complete and a real connector plugs in by swapping the instance — until configured they honestly
  report `operational: false` and return **no** results (never simulated).

Add a connector: implement `KnowledgeSource`, register it in `AppServiceProvider::boot()`. Nothing else
changes.

## Enterprise Search (keyword + semantic)

`EnterpriseSearchService` provides one search across modules: knowledge documents (via the registry)
plus first-party operational entities (incidents, tickets, notifications, workflows, runbooks). Results
are unified, ranked by a deterministic relevance score, and **workspace-scoped**. `mode=semantic`
applies the same deterministic token-overlap ranking (honest "semantic-style" relevance) unless a real
vector source is registered and operational. **Only real indexed data is returned.**

## Enterprise Knowledge Graph v2

`KnowledgeGraphService` builds a deterministic, bounded read-model graph over the workspace's real
entities — project, incidents, assets, alerts, workflows, runbooks, automation executions, tickets,
documents, notifications — with typed nodes and traversable relationships from real foreign keys and
deterministic UUID soft-references. No fabricated nodes or edges.

## Knowledge Assistant

Reuses Quenyx AI through `ModuleAiNarrator`. Capabilities (all editable, never fabricated, never
auto-published):

- **Explain** / **Summarize** a document.
- **Find related** across the workspace (search-grounded).
- **Generate drafts**: KB article, incident summary, executive summary, technical summary, runbook.
- **Copilot** — a grounded conversation reusing the shared Quenyx AI conversation surface.

## API (UUID-only, `workspace` required)

Base: `/api/qynknow` (Sanctum + `throttle:ai-workspace`). Reads/search require `accessAi`; document
writes require `administerAi`; AI surfaces require `can_use_ai`.

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/sources` | Knowledge Source Registry catalog |
| GET/POST | `/documents` | List / create documents |
| GET/PUT/DELETE | `/documents/{uuid}` | Read / update / delete a document |
| GET | `/search?q=` | Enterprise Search (`mode=keyword|semantic`) |
| GET | `/timeline` | Global Timeline |
| GET | `/graph` | Knowledge Graph v2 |
| GET | `/intelligence/overview` | Knowledge overview |
| POST | `/intelligence/copilot` | Knowledge Assistant copilot |
| POST | `/intelligence/related` | Find related |
| POST | `/intelligence/draft` | Generate editable draft |
| POST | `/intelligence/documents/{uuid}/explain` | Explain a document |
| POST | `/intelligence/documents/{uuid}/summarize` | Summarize a document |

## Guarantees

Registry-driven · real indexed data only · never fabricated · drafts always editable and never
auto-published · workspace isolation · RBAC · UUID-only · audited · EN/AR.
