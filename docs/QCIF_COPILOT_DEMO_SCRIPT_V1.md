# QynShield Compliance Copilot — Demo Script v1

> **Audience:** investors · onboarding clients · internal sales
> **Module:** QynShield · **Capability:** Compliance Copilot v0 (+ Sprint 14.1 scope resolver)
> **Mode:** Works in deterministic **mock** mode (`AI_ENABLED=false`) or **AI** mode (`AI_ENABLED=true`).

This script walks through a short, honest demo of the Compliance Copilot. It is grounded in the
platform's deterministic compliance engines and the official corpus. **Do not** claim results,
customers, benchmarks, or revenue that are not present in the live system.

---

## 1. Prerequisites

- A workspace (project) the demo user is a member of, with the **QynShield** module entitled.
- A valid Sanctum session/token for the demo user.
- The compliance corpus seeded for **NCA ECC-2:2024** (`php artisan db:seed --class=ComplianceCorpusSeeder`),
  with at least the controls/requirements you intend to reference (e.g. `1-1-1`, `2-8-4`).
  - Control-explanation and corpus-search prompts return citations **only** for content that exists
    in the corpus. If a referenced control is not yet imported, the Copilot will honestly decline
    (fail closed) rather than invent an answer — demo with controls that are present.
- For **evidence/gap/recommendation** prompts, optionally seed some workspace evidence to make the
  numbers non-zero. With no evidence, the Copilot correctly reports "no evidence / gaps" states.
- No framework/release needs to be passed — **scope auto-resolves** to NCA ECC-2:2024 (Sprint 14.1).
- Optional: set `AI_ENABLED=true` (+ provider key) to demo live AI mode. Otherwise mock mode shows
  the same orchestration with a deterministic preview answer.

Endpoint used throughout:

```
POST /api/workspaces/{project}/compliance/copilot/message
Authorization: Bearer <token>
{ "message": "<prompt>" }
```

---

## 2. The five demo prompts

No `framework`/`release` is supplied — the scope resolver fills them in and every response carries a
`scope` block showing `source: "defaulted"` and the warning `default_scope_used`.

### Prompt 1 — Control explanation
> **"Explain control 1-1-1"**

- **Intent:** `control_explanation`
- **Skills:** `corpus_search` (control profile) + `knowledge_graph` (control context)
- **Expected:** A grounded explanation of control 1-1-1 with **citations** to the official ECC
  source. `skill_results` shows both skills executed; `scope.source = defaulted`.

### Prompt 2 — Corpus search
> **"Find controls related to access management"**

- **Intent:** `search_corpus`
- **Skills:** `corpus_search` (search) + (when a code is present) graph/mapping
- **Expected:** Matching controls/requirements with citations. Demonstrates corpus retrieval —
  **not** vector/semantic search.

### Prompt 3 — Gap summary
> **"Summarize our compliance gaps"**

- **Intent:** `gap_summary`
- **Skills:** `gap_assessment` + `recommendation`
- **Expected:** Deterministic gap totals (requirements assessed / satisfied / with gaps) and the
  number of remediation recommendations. Grounded in the workspace's own evidence + corpus revision.

### Prompt 4 — Evidence status
> **"What evidence do we have for control 2-8-4?"**

- **Intent:** `evidence_status`
- **Skills:** `evidence` + `gap_assessment`
- **Expected:** Count of evidence recorded for the workspace and the related requirement gap state.
  No evidence content is invented; absence is reported honestly.

### Prompt 5 — Remediation priorities
> **"What should we fix first?"**

- **Intent:** `recommendation_summary`
- **Skills:** `gap_assessment` + `recommendation`
- **Expected:** Priority breakdown (critical / high / medium) across deterministic, rule-based
  recommendations, with guidance to address critical and high items first.

---

## 3. Expected system behavior (every response)

- **Structured contract:** `conversation_uuid`, `message_uuid`, `intent`, `mode` (`mock`/`ai`),
  `answer_en`, `answer_ar`, `citations`, `skill_results`, `guardrails`, `warnings`, `scope`,
  `generated_at`. **UUID-only — no numeric IDs.**
- **Scope block:** `framework_key`, `release_code`, `revision_uuid`, `source`, `warnings`.
- **Citation enforcement:** non-empty answers are backed by citations or deterministic grounding;
  otherwise the Copilot fails closed (`citation_validation_failed`). **No citation, no answer.**
- **Deterministic intent:** the same prompt always classifies to the same intent.

---

## 4. What to emphasize

- **Grounded, not generative-guessing:** answers are built from the official corpus and the
  platform's deterministic gap/recommendation engines, then optionally phrased by an LLM under
  strict guardrails.
- **Citation-enforced:** the system refuses to answer without a verifiable source — a key trust and
  audit differentiator for regulated buyers.
- **Provider-agnostic & safe by default:** AI is off by default; when on, calls go only through the
  AI Provider Registry. No prompt/answer content is logged or persisted unless explicitly enabled.
- **Auditable & tenant-isolated:** every turn is workspace-scoped, entitlement-gated, and
  audit-logged (metadata only — never message content).
- **Zero-config scoping:** the resolver picks the right framework/release automatically, so demos
  and onboarding "just work."

---

## 5. What NOT to claim

- Do **not** claim semantic/vector search, document upload, OCR, or automatic remediation — none are
  in v0.
- Do **not** present mock-mode previews as live AI answers; state the `mode`.
- Do **not** fabricate customers, audit pass rates, time savings, benchmarks, or revenue.
- Do **not** imply legal/compliance certification — answers include a "not legal advice" disclaimer.
- Do **not** demo controls that are not seeded; the Copilot will (correctly) decline.

---

## 6. Current limitations (v0 + 14.1)

- Backend/API only — **no UI** yet.
- Closed intent set (5 intents); anything else returns a structured `unsupported_intent`.
- Corpus/graph/mapping answers depend on what is imported into the corpus.
- AI mode produces English answer text from the model; Arabic is populated only when the model
  returns it (guardrail + warning otherwise).
- Scope defaults to a single framework release; multi-framework selection within one prompt is not
  yet supported.

---

## 7. Future roadmap (indicative, non-committal)

- Copilot UI (chat panel with citation chips + "how this was derived" trace).
- Additional intents (control comparison, framework cross-mapping Q&A, evidence guidance).
- Streaming responses via the existing orchestration SSE path.
- Richer bilingual generation and per-intent answer templates.
- Optional retrieval grounding **only** if/when added at the platform layer (not in v0).
