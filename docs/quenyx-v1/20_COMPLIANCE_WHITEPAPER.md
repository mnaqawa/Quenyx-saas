# 20 — Compliance Whitepaper

**Audience:** Compliance leaders, auditors.
**Thesis:** Quenyx delivers **deterministic, provenance‑backed compliance intelligence** — the
prerequisite for trustworthy, auditable automation in regulated environments.

---

## 1. Deterministic compliance intelligence

Quenyx's compliance engine (QCIF) is **deterministic**: the same corpus + the same evidence always
produce the same gaps, the same recommendations, and the same explanations. Decisions come from
**explicit rules**, not from a model's confidence. This is what makes the output defensible.

## 2. Official corpus

Compliance content is **imported from official source documents only**. There is no hand‑authored or
AI‑generated control text. The `ComplianceCorpusValidator` **rejects** fake‑data markers
(`EXAMPLE/SAMPLE/TEST-/FAKE/LOREM/DEMO`, lorem‑ipsum), enforcing the official‑source‑only model.

## 3. Revisions

Each release's content is captured as an **immutable Corpus Revision**; exactly one is **active**.
Revisions guarantee reproducibility and enable controlled rollback. The shipped corpus is **NCA
ECC‑2:2024, Revision v1** (5 domains, 108 controls, 108 requirements).

## 4. Provenance

Every imported control/requirement carries a `source_document_id`, and every import is recorded as an
**import run**. Auditors can trace any corpus element back to the official document and the import
that loaded it.

## 5. Evidence lifecycle

Evidence is recorded per requirement/control with **types** and **statuses** (e.g. compliant,
partially compliant, no evidence, expired, rejected, pending). The executive scorecard reports these
as **counts**, never as invented percentages.

## 6. Gap rules

Gap status is **derived by rule** from evidence and requirements (e.g., a mandatory requirement with
no evidence ⇒ a missing‑evidence gap). Rules are explicit and enumerated in
`ComplianceReasoningRuleSet`.

## 7. Recommendation rules

Recommendations are produced by the same rule set (e.g., missing evidence ⇒ "collect evidence"). Each
recommendation references the **rule that produced it**, so its rationale is transparent.

## 8. Explainability

The explainability service answers "why" questions ("Why is this requirement non‑compliant?", "Why
this recommendation?") directly from the deterministic reasoning — **fired rule IDs**, findings, and
citations — not from generated prose.

## 9. Auditability

Because the corpus is provenance‑tracked, revisions are immutable, reasoning is rule‑based, and
answers are cited, an auditor can independently verify **every** conclusion: source → rule → finding
→ recommendation, each with identifiers.

## 10. Why this reduces hallucination risk

- AI never authors compliance facts; it **renders** deterministic outputs.
- "No citation, no answer" excludes ungrounded statements.
- RAG (when enabled) is **metadata‑only with deterministic fallback** and excludes uncited chunks.
- Tenant evidence is **not embedded** by default.

The model cannot "make up" a control because the control set, its provenance, and the reasoning are
fixed and external to the model.

## 11. Limitations

- Only **one framework (NCA ECC‑2:2024)** is currently loaded.
- Evidence quality depends on what the customer supplies; Quenyx assesses, it does not collect for
  you.
- Quenyx models official controls but makes **no claim of certification or accreditation** by any
  authority.

## 12. Future frameworks

The authority/framework/release/revision model is framework‑agnostic. Additional frameworks can be
imported through the same manifest → validate → revision pipeline, inheriting the same determinism,
provenance, and auditability guarantees.
