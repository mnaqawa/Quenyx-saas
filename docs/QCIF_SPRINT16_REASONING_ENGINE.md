# QCIF Sprint 16 — AI Reasoning Engine

> Module: **QynShield** · Platform: **Quenyx** · Status: **Complete**

## 1. What this sprint IS / IS NOT

This sprint introduces the **Compliance Reasoning Engine** — the deterministic *business brain* that
decides **WHAT** must be answered **before any LLM is called**. It sits between Retrieval and the
Prompt Orchestrator.

**IS** — a deterministic, fully-explainable decision layer: intent + retrieval + skill outputs in →
facts, findings, recommendations, missing information, citations, an answer strategy, and a reasoning
trace out. Same input → same output (uuid5 IDs).

**IS NOT** — anything autonomous or probabilistic. There is:

- ❌ NO autonomous AI / agent loops
- ❌ NO LLM planning or LLM intent detection
- ❌ NO ReAct, Chain-of-Thought, Tree-of-Thought, self-reflection, self-improvement
- ❌ NO probabilistic reasoning / numeric confidence
- ❌ NO AI provider calls, NO vector calls, NO embeddings, NO OpenAI inside the reasoning core
- ❌ NO database access inside the reasoning core
- ❌ NO natural-language answer (only structured reasoning)

The reasoning trace is **business reasoning**, never a hidden chain-of-thought.

## 2. Business reasoning & the new Copilot flow

```
User
  ↓
Intent              (deterministic classifier — Sprint 14)
  ↓
Skills              (existing AI Skills via Skill Router — Sprint 10)
  ↓
Retrieval           (deterministic chunks + citations — Sprint 15)
  ↓
Reasoning Engine    (deterministic decision + facts + findings + strategy — Sprint 16) ◀ NEW
  ↓
Prompt Orchestrator (composes the prompt from ReasoningOutput, not raw skills)
  ↓
Provider            (only renders the already-decided reasoning; mock when AI disabled)
```

The Prompt Orchestrator now consumes **`ReasoningOutput`** via `composeFromReasoning()`. The model's
only job is to *render* the deterministic facts/findings/recommendations in natural language while
honoring the guardrails — it does **not** decide what to answer.

## 3. Reasoning services

All under `app/Services/Compliance/Reasoning/` (DB-free, AI-free):

| Service | Responsibility |
|---|---|
| `ComplianceReasoningEngine` | Orchestrates: decide → extract facts → apply rules → merge citations → build trace + explanation → `ReasoningOutput` |
| `ComplianceReasoningPlanner` | Resolves the deterministic decision type from intent + context signals |
| `ComplianceReasoningRuleSet` | The deterministic IF/THEN business rules that produce findings, recommendations, and missing information |

DTOs under `app/DataTransferObjects/Compliance/Reasoning/`:
`ComplianceReasoningContext` (input), `ComplianceReasoningDecision`, `ComplianceReasoningExplanation`,
`ReasoningFinding`, `ReasoningRecommendation`, `ReasoningTraceNode`, `ReasoningTrace`,
`ReasoningOutput`. Enum: `App\Enums\Compliance\Reasoning\ComplianceReasoningDecisionType`.

## 4. Decision model (deterministic)

Eight decisions, resolved by rules (never by an LLM). Base mapping from the Copilot intent, then
refined to `framework_mapping` / `knowledge_navigation` only by explicit context signals
(mapping/graph data present **and** matching query keywords):

| Decision type | Source intent / signal | Answer strategy | Corpus citations required |
|---|---|---|---|
| `control_explanation` | `control_explanation` | `explain_control_from_corpus_citations` | yes |
| `gap_analysis` | `gap_summary` | `summarize_gap_findings_with_priorities` | no (engine-grounded) |
| `evidence_review` | `evidence_status` | `summarize_evidence_status` | no |
| `recommendation` | `recommendation_summary` | `prioritize_recommendations` | no |
| `framework_mapping` | control_explanation + mapping signal | `explain_cross_framework_mapping` | yes |
| `knowledge_navigation` | control_explanation + navigation signal | `navigate_related_controls` | yes |
| `search_summary` | `search_corpus` | `summarize_search_results` | yes |
| `unsupported` | `unsupported` | `decline_unsupported` | no |

## 5. Business rules

Each rule is an explicit IF/THEN over deterministic metrics derived from the skill payloads. Findings
and recommendations get **rule-assigned** severities/priorities (never probabilistic) and
**deterministic uuid5** IDs.

| Rule ID | Applies to | Condition | Produces |
|---|---|---|---|
| `R-EVIDENCE-MISSING` | evidence_review, gap_analysis, control_explanation | no evidence **AND** a mandatory requirement exists | finding `missing_evidence` (high) + reco `collect_required_evidence` (high) |
| `R-GAP-OPEN` | gap_analysis, recommendation, evidence_review | open gaps > 0 | finding `open_gaps` (high) + reco `remediate_open_gaps` (high) |
| `R-COMPLIANT` | gap_analysis | requirements > 0 **AND** gaps = 0 | finding `compliant` (info) |
| `R-RECO-CRITICAL` | recommendation, gap_analysis | critical recommendations > 0 | finding `critical_actions_pending` (critical) + reco `address_critical_actions` (critical) |
| `R-RECO-HIGH` | recommendation | high recommendations > 0 | finding `high_priority_actions_pending` (high) + reco `address_high_priority_actions` (high) |
| `R-CORPUS-CITATIONS-MISSING` | any citation-required decision | no corpus citations | missing_information `corpus_citations` + warning `reasoning_citation_context_missing` |
| `R-NO-DATA` | any | no skill payloads at all | missing_information `no_supporting_data` + warning `reasoning_no_supporting_data` |

> The canonical Task-3 example — *IF no evidence AND requirement mandatory THEN finding=missing
> evidence, recommendation=collect required evidence, priority=High* — is `R-EVIDENCE-MISSING`.

## 6. Reasoning graph (trace)

The engine emits a `ReasoningTrace`: a single **root decision node** with four group children —
`facts`, `findings`, `recommendations`, `missing_information` — each containing leaf nodes. Every
node has:

- `uuid` (deterministic uuid5)
- `reason` (explicit business reason — not a chain-of-thought)
- `source` (`decision` / `group` / `fact:*` / `rule:<RULE-ID>` / `missing`)
- `citations`
- `parent`
- `children`

`ReasoningTrace::flatten()` gives a deterministic depth-first node summary for auditing.

## 7. Output contract

`ReasoningOutput::toArray()` returns **structured reasoning only** (no NL answer):

```json
{
  "decision": { "type": "gap_analysis", "answer_strategy": "summarize_gap_findings_with_priorities", "requires_corpus_citations": false, "notes": [ ... ] },
  "answer_strategy": "summarize_gap_findings_with_priorities",
  "facts": [ { "uuid": "…", "label": "gap_totals", "source": "gap", "value": { "requirements": 10, "satisfied": 6, "gaps": 4 } } ],
  "findings": [ { "uuid": "…", "rule_id": "R-GAP-OPEN", "code": "open_gaps", "severity": "high", "summary_en": "…", "summary_ar": "…", "citations": [ … ] } ],
  "recommendations": [ { "uuid": "…", "rule_id": "R-GAP-OPEN", "action": "remediate_open_gaps", "priority": "high", … } ],
  "missing_information": [ … ],
  "citations": [ … ],
  "guardrails": { "use_only_provided_context": true },
  "warnings": [ … ],
  "reasoning_trace": { "uuid": "…", "reason": "decision: …", "source": "decision", "children": [ … ] },
  "explanation": { "decision_type": "gap_analysis", "applied_rule_ids": ["R-GAP-OPEN", "R-RECO-CRITICAL"], … }
}
```

## 8. Copilot integration

`ComplianceCopilotService::handle()` now builds a `ComplianceReasoningContext` from the intent, scope,
skill payloads, corpus citations, grounding references, and the (Sprint 15) retrieval chunks, then
calls the engine. When `AI_COPILOT_REASONING_ENABLED=true` (default), the prompt is composed from the
`ReasoningOutput`; otherwise it falls back to the legacy skill-composed prompt. The Copilot response
gains a `reasoning` block (`reasoning` in the API `data`). Citation enforcement, guardrails, scope,
mock/AI mode, and the optional `retrieval_context` block are unchanged.

Config (`config/ai.php`):

- `ai.copilot.reasoning_enabled` — `AI_COPILOT_REASONING_ENABLED` (default **true**)
- `ai.copilot.retrieval_enabled` — `AI_COPILOT_RETRIEVAL_ENABLED` (default false, Sprint 15)

## 9. Guardrails & security

- **Determinism**: rule-based decisions + uuid5 IDs ⇒ identical inputs produce identical outputs.
- **Fail-closed citations**: citation-required decisions with no corpus citations raise
  `R-CORPUS-CITATIONS-MISSING` (the Copilot citation verifier still enforces "no citation → no
  answer").
- **No autonomy**: no agent loops, no LLM planning, no self-reflection/improvement.
- **Isolation**: the reasoning core performs no DB access, no AI/provider calls, no vector/embedding
  calls (verified by grep + DI). It only consumes the data handed to it.
- **UUID-only**: no numeric IDs are serialized.
- **Auditability**: the explanation + trace make every decision and rule firing inspectable.

## 10. Future AI evolution

This deterministic layer is the safe substrate for later AI growth:

1. **Today** — the engine fully decides; the LLM only renders.
2. **Next** — a future provider may *draft* candidate phrasings, still constrained to the
   deterministic facts/findings/citations (no new facts, no reprioritization).
3. **Later** — when a real RAG/vector layer (Sprint 15 `VectorRetrievalProviderInterface`) lands, it
   feeds richer chunks into the same `ComplianceReasoningContext`; the rule set and decision model
   stay deterministic. Any future "reasoning assist" must remain explainable and rule-bounded — no
   autonomous agents.

## 11. QA results

DB-free / AI-free harness (framework booted without a DB connection; engine resolved from the
container to also verify DI) — **30/30 passed**:

- decision resolution for all 8 types incl. mapping/navigation refinement and unsupported
- `R-EVIDENCE-MISSING`, `R-GAP-OPEN`, `R-RECO-CRITICAL`, `R-CORPUS-CITATIONS-MISSING` fire correctly
- findings/recommendations carry rule-assigned severity/priority + citations
- citations preserved; fail-closed missing-information on absent corpus citations
- reasoning trace: root `decision` node + 4 group children, parents/UUIDs consistent, flatten works
- **identical inputs → identical outputs** (excluding the `generated_at` timestamp); stable uuid5 IDs
- UUID-only (no numeric id keys leaked)
- `php -l` clean on all new/changed files
- `route:list` unchanged (no new endpoints; Copilot routes still resolve with the new DI dependency)
- grep confirms no DB / AI-provider / vector / embedding / OpenAI usage in the reasoning core

Full database-backed runtime QA (live corpus/evidence/gaps through the real skills) is to be run on a
properly configured server, as the local PHP environment lacks the DB extensions.
