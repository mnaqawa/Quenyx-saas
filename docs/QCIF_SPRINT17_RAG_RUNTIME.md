# QCIF Sprint 17 — RAG Runtime & Vector Provider Foundation

> Module: **QynShield** · Status: **Complete** · Default state: **OFF (feature-flagged)**

This sprint introduces the real RAG runtime foundation: a provider-agnostic vector layer, an
indexing lifecycle for the approved corpus, a hybrid (deterministic + vector) retrieval service, and
a bounded, citation-safe RAG context builder. Everything is **OFF by default** and **fallback-safe**:
when vectors are unavailable the system degrades to the deterministic retrieval already shipped in
Sprint 15. RAG **never** bypasses framework release, active revision, workspace entitlement,
citations, or the Reasoning Engine.

---

## 1. Architecture

```
User
  │
  ▼
Intent (Copilot Planner)                ← deterministic
  │
  ▼
Skills (AI Skills Framework)            ← reuse existing services, no AI calls
  │
  ▼
Retrieval (Sprint 15, deterministic)    ← ranked, cited chunks
  │
  ▼
Hybrid Retrieval (Sprint 17)            ← deterministic FIRST + optional vector
  │     • merge, de-dupe by entity_uuid+chunk_type
  │     • deterministic rank reasons first, then `vector_semantic_match`
  │     • vector failure ⇒ fallback + `vector_provider_unavailable`
  ▼
Reasoning Engine (Sprint 16)            ← deterministic decision: facts/findings/recs
  │
  ▼
RAG Context Builder (Sprint 17)         ← bounded, cited-only context package (NO AI call)
  │
  ▼
Prompt Orchestrator → Provider          ← RETRIEVED CONTEXT appended to the reasoning prompt
```

Key principle: **the vector layer is supplementary**. The deterministic corpus, citations, and the
Reasoning Engine remain the source of truth. Vector semantic matches can only *add* corpus-derived,
cited candidates after the deterministic ones — never replace or reorder them ahead of deterministic
reasons, and never introduce uncited content.

---

## 2. Vector Provider Model

A concrete provider implements the existing `VectorRetrievalProviderInterface` (Sprint 15 seam):

- **`OpenAiVectorRetrievalProvider`** (`app/Services/Compliance/Rag/Providers/`)
  - Uses **OpenAI embeddings via the existing `AiProviderRegistry`** — there are **no direct OpenAI
    HTTP calls** in the RAG layer; everything goes through the provider classes.
  - Runs in **METADATA-ONLY mode**: without a real vector backend (e.g. `pgvector`), it stores
    embedding *metadata* only and **never fabricates vector similarity**. `search()` returns no
    semantic candidates, so retrieval falls back to deterministic results.
  - `supportedCapabilities()` advertises `mode: metadata_only`, `semantic_search: false`,
    `fakes_similarity: false`.

- **`VectorRetrievalProviderRegistry`** resolves the provider from `VECTOR_PROVIDER`. It returns
  `null` when RAG is disabled, no provider is configured, or the provider class is missing — so the
  hybrid layer transparently uses deterministic retrieval.

### Configuration (`config/ai.php` → `rag`)

| Env | Default | Meaning |
|---|---|---|
| `RAG_ENABLED` | `false` | Master switch for the RAG runtime |
| `VECTOR_PROVIDER` | `null` | `openai` or null/unknown ⇒ deterministic only |
| `EMBEDDINGS_ENABLED` | `false` | Allow embedding computation via the provider |
| `OPENAI_EMBEDDINGS_MODEL` | `null` | Embeddings model (never hardcoded) |
| `RAG_INDEX_TENANT_EVIDENCE` | `false` | Tenant evidence is **never** indexed unless explicitly enabled |
| `RAG_TOKEN_BUDGET` | `6000` | Approx. token budget for the bounded context package |
| `AI_COPILOT_RAG_ENABLED` | `false` | Attach RAG context inside the Copilot flow |
| `COMPLIANCE_RAG_RATE_LIMIT` | `30` | Per-minute rate limit for the RAG API |

---

## 3. Indexing Lifecycle

The indexer (`RagIndexService`) is the **only DB boundary** for building the index. It:

- Resolves the framework release and its **approved active corpus revision** (status `Active`).
- Enumerates **corpus** controls + requirements into deterministic, cited chunk descriptors
  (`entity_type`, `entity_uuid`, `entity_code`, `chunk_type`, `content_hash`, bilingual text,
  `source_document_key`, `official_reference`, `source_page`, and per-chunk citations).
- Upserts rows **idempotently** (unique per `corpus_revision_id + entity_uuid + chunk_type`), keyed
  by a `content_hash` so re-runs are no-ops when content is unchanged.
- **Never indexes tenant evidence** (only the public corpus is enumerated).
- Optionally computes embeddings (only when `EMBEDDINGS_ENABLED=true`) through the vector provider;
  metadata only is persisted (no raw vectors; `vector_id` references an external vector store when
  one exists later).
- Supports **dry-run** (plan + counts, persists nothing).

### Jobs (`app/Jobs/Compliance/Rag/`)

- `IndexCorpusRevisionForRag(framework, release, dryRun)` — index the active revision.
- `IndexRetrievalChunk(framework, release, descriptor, dryRun)` — incremental single-chunk upsert.
- `DeleteVectorIndexForRevision(framework, release)` — delete metadata + any external vectors.

All jobs are idempotent, scoped to the active revision, and carry framework/release/revision UUID +
source citations in the persisted metadata.

---

## 4. Metadata Tables

`rag_vector_indexes` — one row per `(provider, corpus_revision_id)`:

`uuid, provider, framework_release_id, corpus_revision_id, status, embedding_model, chunk_count,
dimensions, metadata, indexed_at`.

`rag_vector_chunks` — one row per indexed chunk (unique `corpus_revision_id + entity_uuid +
chunk_type`):

`uuid, rag_vector_index_id, provider, framework_release_id, corpus_revision_id, entity_type,
entity_uuid, entity_code, chunk_type, content_hash, text_en, text_ar, embedding_model, vector_id
(nullable), source_document_key, official_reference, source_page, metadata (citations), indexed_at`.

No sensitive tenant data is stored. No raw embedding vectors are stored (a future `pgvector`/vector
DB holds those; `vector_id` is the reference).

---

## 5. Hybrid Retrieval Behavior

`ComplianceHybridRetrievalService`:

1. Runs **deterministic retrieval** (Sprint 15) first.
2. If RAG is enabled and a provider resolves, calls `provider->search()`.
3. **Merges** vector chunks into the deterministic result, **de-duplicating by
   `entity_uuid + chunk_type`** (deterministic entries win and stay ranked first).
4. **Preserves citations** and **drops any uncited vector chunk** (`vector_uncited_chunks_dropped`).
5. **Ranks** deterministic reasons first, then the vector reason **`vector_semantic_match`**.
6. **No numeric confidence is ever exposed.**

Fallback safety:

- Provider unavailable / `search()` throws ⇒ deterministic result + warning
  **`vector_provider_unavailable`**.
- Provider present but metadata-only (no candidates) ⇒ deterministic result stands; the scope block
  records `vector_provider` and `vector_candidates: 0`.

---

## 6. RAG Context Builder

`ComplianceRagContextBuilder.build(RetrievalResult, ReasoningOutput, SkillResults)` — **no AI call**.
Outputs:

- `context_package` — bounded, **cited-only** chunks (uncited chunks are excluded).
- `citations` — de-duplicated, UUID-only.
- `guardrails` — reasoning + retrieval guardrails, plus enforced `rag_cited_chunks_only`,
  `rag_no_uncited_context`, `rag_corpus_grounded`.
- `token_budget` — `budget`, `estimated_tokens_used`, `remaining`, `included_chunks`,
  `excluded_chunks`.
- `excluded_chunks` — each with a `reason` (`missing_citation` or `token_budget_exceeded`).
- `reasoning` summary + `skills` summary + `generated_at`.

This guarantees RAG **never** returns uncited chunks and never silently drops context.

---

## 7. Copilot Integration

When `AI_COPILOT_RAG_ENABLED=true`, the Copilot flow becomes:

```
Intent → Skills → Retrieval → Hybrid Retrieval → Reasoning → RAG Context → Prompt Orchestrator → Provider
```

The bounded RAG context is appended to the reasoning prompt as a **RETRIEVED CONTEXT** block
(supplementary, cited corpus excerpts — never a substitute for the deterministic facts). The
response gains an optional `rag_context` block. Vector warnings (e.g.
`vector_provider_unavailable`) surface in `warnings`.

When disabled, the **Sprint 16 flow is unchanged** (no hybrid step, no RAG context).

---

## 8. Commands & API

```bash
php artisan compliance:rag:index  --framework=nca-ecc --release=2:2024 --dry-run
php artisan compliance:rag:index  --framework=nca-ecc --release=2:2024
php artisan compliance:rag:status --framework=nca-ecc --release=2:2024
php artisan compliance:rag:delete --framework=nca-ecc --release=2:2024
```

Workspace endpoint (returns the **RAG context only**, never a final AI answer):

```
POST /api/workspaces/{project}/compliance/rag/query
POST /api/projects/{project}/compliance/rag/query
Body: { "query": "...", "framework": "nca-ecc?", "release": "2:2024?", "limit": 20? }
```

Access: `auth:sanctum` + project membership (`ProjectPolicy::view`) + QynShield entitlement
(`project.qynshield`) + audit logging + `throttle:compliance-rag`. An explicit, non-existent
framework/release returns **422 `scope_unresolved`**.

---

## 9. Safety Rules (enforced)

- RAG never returns **uncited** chunks (dropped at hybrid merge and at context build).
- RAG never bypasses **framework release**, **active revision**, **workspace entitlement**,
  **citation verifier**, or the **reasoning engine**.
- Tenant evidence is **never** embedded by default.
- Vector failures **fall back** to deterministic retrieval with `vector_provider_unavailable`.
- No direct OpenAI calls outside provider classes; embeddings route through `AiProviderRegistry`.
- UUID-only throughout (no numeric IDs leak to responses).
- No numeric similarity/confidence is exposed.

---

## 10. Future pgvector / Vector DB Path

This foundation is intentionally metadata-only. To enable true semantic retrieval later:

1. Add a `pgvector` column (or external vector DB) and store the embedding alongside
   `rag_vector_chunks` (or in the external store, referenced by `vector_id`).
2. Implement `search()` in a provider to run real ANN/cosine search **scoped to the active
   revision**, returning corpus-derived **cited** chunks tagged `vector_semantic_match`.
3. Everything downstream (hybrid merge, de-dupe, citation safety, RAG context budget, Copilot
   integration) already supports it — no contract changes required.

The deterministic layers (retrieval, reasoning, citations) remain authoritative regardless of the
vector backend.
