# 04 — Executive Whitepaper

**Audience:** CIO, CTO, CISO, board.
**Purpose:** Explain the concept and governance model behind Quenyx vOPS HUB without unsupported
claims.

---

## 1. The Virtual Operations Center concept

Enterprises run operations, compliance, and (increasingly) AI as separate disciplines with separate
tools and separate data. A **Virtual Operations Center (vOPS)** collapses these into one platform
with **one source of truth**, one identity and entitlement model, one audit trail, and one
**governable AI layer**. Quenyx vOPS HUB is that center: monitoring (QynSight) and compliance
(QynShield/QCIF) sharing a workspace model and a common AI platform.

## 2. Why operations, compliance, and AI converge

- **Operations produces the facts** — what infrastructure exists and how it behaves.
- **Compliance interprets the facts** against regulatory frameworks and evidence.
- **AI explains and accelerates** — *if* it is grounded in those facts and constrained to cite them.

Run separately, each is weaker: monitoring lacks governance context, compliance lacks live
operational truth, and AI lacks trustworthy grounding. Converged, they compound.

## 3. Enterprise pain points

- Fragmented monitoring → no single operational picture.
- Manual, spreadsheet‑driven compliance → slow, error‑prone, hard to audit.
- Generic LLM tools → hallucination, no provenance, unacceptable in regulated settings.
- Tool sprawl → duplicated identity, access, and audit surfaces, each a risk.

## 4. Saudi / GCC compliance context

Quenyx targets regulated enterprises in the Saudi/GCC market, where authorities such as the
**National Cybersecurity Authority (NCA)** publish control frameworks. Quenyx ships the **NCA
ECC‑2:2024** framework as **Corpus Revision v1** (5 domains, 108 controls, 108 requirements),
modelled from official source documents.

> *We make no claim of formal certification, endorsement, or accreditation by any authority. The
> corpus models official published controls; certification is the customer's responsibility.*

## 5. AI governance model

Quenyx treats AI as a **renderer over an expert platform**, not as the expert:

- **Off by default.** No real model is called unless explicitly enabled (`AI_ENABLED`).
- **Deterministic first.** A rule‑based Reasoning Engine decides *what* is true and *what* to answer
  before any model is involved.
- **Cited or silent.** The Copilot enforces citations — "no source, no answer."
- **Provider‑agnostic.** Models are selected via a provider registry; no model is hardcoded; a mock
  provider allows safe operation with zero external calls.
- **Privacy‑preserving.** Prompt logging and conversation persistence are **off by default**; tenant
  evidence is **never embedded** by default.

## 6. Why deterministic engines matter

Determinism makes outputs **reproducible and auditable**: the same inputs always yield the same
reasoning and the same citations. In regulated environments, "the model felt confident" is not an
acceptable basis for a control decision; "rule R‑EVIDENCE‑MISSING fired because requirement X has no
evidence" is. Quenyx puts the deterministic engine in charge and uses AI only to phrase the result.

## 7. Why citations and provenance matter

Every compliance entity in Quenyx carries provenance back to an **official source document**, and
every Copilot answer must cite the corpus. This:

- eliminates a whole class of AI hallucination risk,
- makes answers defensible to auditors,
- and lets customers verify, not just trust.

## 8. Quenyx roadmap (executive view)

- **Now (Phase I complete):** QynSight production; QynShield backend/API; shared AI platform;
  deterministic reasoning; executive demonstration layer.
- **Next (Sprint 20+):** close verification gaps; mature QynShield UI; graduate real‑model AI and
  RAG from feature‑flagged to GA; build first‑class QynSight AI on the reserved adapter; expand the
  compliance corpus to additional frameworks.

**Bottom line for the board:** Quenyx has built a governable foundation where AI is constrained by
deterministic, provenance‑backed engines — the prerequisite for safe AI adoption in regulated
operations.
