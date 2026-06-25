# QCIF Sprint 15 — Retrieval & RAG Optimization Foundation

> Module: **QynShield** · Platform: **Quenyx** · Status: **Complete**

## 1. What this sprint IS / IS NOT

**IS** — a deterministic retrieval layer that turns a user query + mode into ranked, cited
"chunks" suitable for a future RAG pipeline and for richer Copilot answers. It reuses the existing
**AI Skills** (corpus search, knowledge graph, cross-framework mapping, evidence, gap assessment,
recommendation) via the **Skill Router**, transforms their deterministic output into a stable chunk
contract, and ranks the chunks with **named, explainable rank reasons**.

**IS NOT** — full RAG. This sprint ships:

- ❌ NO vector database
- ❌ NO embeddings generation
- ❌ NO OpenAI File Search
- ❌ NO external vector / retrieval provider
- ❌ NO AI provider calls inside the retrieval layer
- ❌ NO unrestricted chatbot
- ❌ NO UI

Everything is deterministic and reproducible: the same inputs always produce the same chunks, the
same order, and the same explanations.

## 2. Retrieval architecture

```
RetrievalQuery ─▶ ComplianceRetrievalService
                    │
                    ├─ ComplianceCopilotScopeResolver   (ONLY DB touch: framework/release/revision)
                    ├─ ComplianceRetrievalPlanner       (mode ─▶ deterministic skill requests)
                    ├─ AiSkillRouter.executeMany()      (reuse existing AI Skills — no AI calls)
                    ├─ ComplianceRetrievalContextBuilder(skill payloads ─▶ raw chunks + relations)
                    ├─ ComplianceRetrievalRanker        (rank reasons + deterministic ordering)
                    └─ ComplianceRetrievalCitationMerger(merge + de-dupe citations)
                    │
                    ▼
                RetrievalResult { mode, scope, chunks, citations, rank_explanations, guardrails, warnings }
```

| Service | Responsibility | DB? | AI? |
|---|---|---|---|
| `ComplianceRetrievalService` | Orchestrates the pipeline | only via scope resolver | no |
| `ComplianceRetrievalPlanner` | Maps mode → fixed set of `AiSkillRequest` (+ extracts control code) | no | no |
| `ComplianceRetrievalContextBuilder` | Walks skill payloads → entity chunks + relation UUID sets | no | no |
| `ComplianceRetrievalRanker` | Assigns rank reasons, deterministic ordering, deterministic chunk UUIDs | no | no |
| `ComplianceRetrievalCitationMerger` | Merges/de-dupes corpus + chunk citations | no | no |

The retrieval core (planner, context builder, ranker, citation merger, service) performs **no
direct database access**. The only DB boundary is `ComplianceCopilotScopeResolver` (reused from
Sprint 14.1), which resolves framework/release/revision and defaults to `NCA ECC‑2:2024`.

## 3. Retrieval modes

Each mode is deterministic and maps to a fixed set of skills:

| Mode | Corpus/graph skills | Tenant skills | Notes |
|---|---|---|---|
| `corpus_only` | `corpus_search` | — | text/code search of the corpus |
| `graph_expanded` | `corpus_search`, `knowledge_graph` | — | adds graph neighbors (needs a code) |
| `evidence_aware` | `corpus_search` | `evidence` | tags chunks that have related evidence |
| `gap_aware` | `corpus_search` | `gap_assessment` | tags chunks linked to gap findings |
| `recommendation_aware` | `corpus_search` | `gap_assessment`, `recommendation` | tags chunks linked to recommendations |
| `copilot_context` | `corpus_search`, `knowledge_graph`, `framework_mapping` | `evidence`, `gap_assessment`, `recommendation` | broadest context, used by Copilot |

Code-dependent skills (`knowledge_graph` control context, `framework_mapping`) are only requested
when the query contains a control code, so every mode **degrades safely** when no code / no tenant
data is present (the result simply has fewer chunks and a `no_chunks_retrieved` warning — never an
error).

## 4. Chunk model (contract)

DTOs live in `app/DataTransferObjects/Compliance/Retrieval`:

`RetrievalChunk` — the future RAG unit:

| Field | Description |
|---|---|
| `uuid` | deterministic chunk UUID (`uuid5(entity_uuid \| chunk_type \| revision_uuid)`) |
| `chunk_type` | entity type of the chunk (`control` / `requirement` / `domain` / …) |
| `entity_type` | corpus entity type |
| `entity_uuid` | corpus entity UUID (never a numeric id) |
| `entity_code` | display/normalized code |
| `text_en` / `text_ar` | bilingual text (requirement text → description → title) |
| `source_document_key` | official source document key |
| `official_reference` | clause/article reference |
| `source_page` | page reference |
| `revision_uuid` | corpus revision the chunk belongs to |
| `citations` | list of `RetrievalCitation` (≥ 1 per chunk) |
| `metadata` | `{ origins: [...skills...], source_reference }` |

Supporting DTOs: `RetrievalQuery`, `RetrievalResult`, `RetrievalCitation`,
`RetrievalScoreExplanation`. **UUID‑only** — no numeric IDs are ever serialized.

Only `control`, `requirement`, `domain`, `objective`, `control_objective` entities become chunks.
Framework / release / revision / source‑document blocks are explicitly excluded.

## 5. Ranking model (explainable, no scores)

Ranking uses **named rank reasons**, never a numeric or probabilistic confidence, and never AI.
Allowed reasons (strongest → weakest precedence):

`exact_code_match` → `normalized_code_match` → `title_match` → `requirement_text_match` →
`graph_neighbor` → `evidence_related` → `gap_related` → `recommendation_related` →
`manual_priority` → `fallback`

- A chunk's **primary reason** is the strongest reason it qualifies for.
- Ordering is `[primary precedence, entity_code, entity_uuid]` — fully deterministic and stable.
- Each chunk has a `RetrievalScoreExplanation` with `primary_reason`, the ordered `reasons`, and a
  1‑based `position` (the deterministic rank order — not a confidence).

Rank reasons are derived from: the extracted query code, query terms (≥ 3 chars), and which skill
"origin" surfaced the entity (e.g. `knowledge_graph` → `graph_neighbor`; an entity UUID referenced
by the evidence/gap/recommendation skills → `*_related`).

## 6. Retrieval API

```
POST /api/workspaces/{project}/compliance/retrieval/query   (alias: /api/projects/{project}/...)
```

Request:

```json
{ "query": "access management", "mode": "corpus_only", "framework": "nca-ecc", "release": "2:2024", "limit": 20 }
```

`mode` defaults to `corpus_only`; `framework`/`release` are optional (auto‑resolved, defaulting to
NCA ECC‑2:2024); `limit` is 1–50 (default 20).

Response (`data`):

```json
{
  "mode": "corpus_only",
  "scope": { "framework_key": "nca-ecc", "release_code": "2:2024", "revision_uuid": "…", "source": "explicit|defaulted", "warnings": [] },
  "chunks": [ { "uuid": "…", "chunk_type": "control", "entity_uuid": "…", "entity_code": "1-1-1", "text_en": "…", "citations": [ … ], "metadata": { "origins": ["corpus_search"] } } ],
  "citations": [ … ],
  "rank_explanations": [ { "chunk_uuid": "…", "primary_reason": "exact_code_match", "reasons": [ … ], "position": 1 } ],
  "guardrails": { "use_only_provided_context": true },
  "warnings": []
}
```

Security & operations:

- `auth:sanctum` (outer route group)
- **project membership** via `project.qynshield` middleware + `ProjectPolicy::view`
- **QynShield entitlement** via `project.qynshield`
- **audit logging** — `compliance_retrieval_access` records `user_id`, `project_id`, `mode`,
  `framework`, `release`, `endpoint`, `timestamp` only. **The query text and retrieved content are
  never logged.**
- **rate limiting** — `throttle:compliance-retrieval` (default 60/min, `COMPLIANCE_RETRIEVAL_RATE_LIMIT`)
- **caching** — revision‑stable modes (`corpus_only`, `graph_expanded`) with an explicit
  framework+release are cached via the corpus cache (keyed by revision). Tenant‑aware modes
  (evidence/gap/recommendation/copilot_context) are **not** cached.
- An explicit framework/release that does not exist returns **HTTP 422** with
  `error_code: scope_unresolved`.

## 7. Copilot integration

The existing Copilot flow is **unchanged by default**. When `AI_COPILOT_RETRIEVAL_ENABLED=true`
(`ai.copilot.retrieval_enabled`), the Copilot attaches an optional `retrieval_context` block to its
response, built by `ComplianceRetrievalService::fromResponses()` from the **same skill responses
already executed** for the answer — skills are **not** run twice, and no AI/DB/vector work is added.
The block is a compact form (`mode`, `scope`, `chunk_count`, trimmed `chunks`, `citations`,
`rank_explanations`, `warnings`). When the flag is off, no `retrieval_context` key is emitted.

## 8. Future vector / RAG path

`app/Contracts/Compliance/Retrieval/VectorRetrievalProviderInterface` declares the seam a future
RAG implementation would plug into — `index()`, `search()`, `delete()`, `health()`,
`supportedCapabilities()` (plus `key()`). **Interface only**: no implementation, no embeddings, no
vector DB in this sprint. A future provider would be resolved through a registry (mirroring the AI
Provider Registry), and the deterministic retrieval layer would become the *hybrid* fallback /
candidate generator feeding the vector search.

## 9. Security summary

- UUID‑only across all DTOs and API output (verified: no `id` / `*_id` keys serialized).
- Citation‑backed: every chunk carries ≥ 1 citation; corpus citations are merged and de‑duplicated.
- No prompt/content logging; audit records metadata only.
- Workspace‑scoped + QynShield‑entitled + rate‑limited.
- Retrieval core is DB‑free except the single sanctioned scope‑resolution boundary.
- No AI provider calls, no embeddings, no vector store.

## 10. QA results

DB‑free / AI‑free harness through ContextBuilder → Ranker → CitationMerger (24/24 passed):

- exact control‑code retrieval (`exact_code_match`, ranked first)
- text search retrieval (`title_match`, `requirement_text_match`)
- graph‑expanded retrieval (`graph_neighbor`; framework node correctly **not** chunked)
- evidence / gap / recommendation tagging (`*_related`) from tenant skills
- modes degrade safely with empty input (zero chunks, no error)
- citations present (every chunk ≥ 1 citation), revision UUID propagated
- UUID‑only (no numeric id keys leaked)
- deterministic across repeated runs
- `php -l` clean on all new/changed files; `route:list` shows both workspace + project aliases

Full database‑backed runtime QA (live corpus, evidence, gaps) is to be executed on a properly
configured server environment, as the local PHP environment lacks the required DB extensions.
