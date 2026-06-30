# 22 — QynAsset Guide (Asset Intelligence)

> **Quenyx vOPS HUB — Document Metadata**
>
> | Field | Value |
> |---|---|
> | Document Version | 1.0 |
> | Software Version | v1.0.0 |
> | Applies To | Quenyx vOPS HUB v1.0.0 |
> | Classification | Confidential — Product |
> | Owner | QynAsset Engineering |
> | Status | Released |
> | Last Updated | 2026-06-30 |
> | Document Type | Module guide |
>
> **Revision History**
>
> | Version | Date | Notes |
> |---|---|---|
> | 1.0 | 2026-06-30 | Initial QynAsset guide: Asset Intelligence (Sprint 22) as the second production AI adapter, grounded strictly in the real discovered inventory. |

**Audience:** Operators, asset managers, and engineers using QynAsset.
**Scope:** QynAsset **Asset Intelligence** — the AI surface that explains the real, discovered asset
inventory. It is the **second production AI adapter** on the shared Quenyx AI Platform.

---

## 1. What QynAsset Intelligence is

QynAsset Intelligence turns the inventory you actually collect into **explainable asset
intelligence**. It reuses the shared Quenyx AI Platform end-to-end (provider abstraction, prompt
orchestration, conversations, audit) through the **AI Adapter Platform** (see docs 07 and 23) — no AI
logic is duplicated.

**Ground truth, never invented.** An "asset" is a **discovered host**: an `observe_targets_hosts`
record enriched by its enrolled agent (`agents`) and that agent's latest inventory push
(`agent_inventories`). Hardware/capacity reuses Capacity Planning; dependencies reuse the
Infrastructure Map. Capabilities with **no data source** (software licenses; warranty / end-of-life /
end-of-support dates) are reported honestly as **not collected** — they are never fabricated.

## 2. Capabilities

| Capability | What it explains | Data source |
|---|---|---|
| Asset Discovery Intelligence | New, changed, inactive, unknown, duplicate assets; discovery confidence | hosts + agents + inventory timestamps/identity |
| CMDB Intelligence | "Which assets are unmanaged / duplicated / without owners?" etc. | inventory summary + discovery |
| Lifecycle Intelligence | Replacement priority, business impact (warranty/EOL **not collected**) | agent version/age, activity |
| Asset Relationship Analysis | Blast radius / SPOF if an asset fails | Infrastructure Map topology |
| Dependency Intelligence | What an asset depends on / serves; subnet neighbors | service checks + /24 grouping |
| License Intelligence | Utilization, compliance risk — **honest "not collected"** until integrated | (none today) |
| Hardware Intelligence | CPU cores (from inventory) + utilization/growth | inventory + Capacity Planning |
| Operational Asset Summary | Inventory rollup, with/without agent, online/inactive | inventory summary |
| Asset Health Summary | Active vs inactive/stale, by OS, by source | agent status + heartbeat |
| Risk Summary | Evidence-based risks (inactive, duplicate, uncovered, capacity) | recommendations engine |

## 3. The dashboard

`Asset Intelligence` (`/app/workspaces/:id/qynasset/intelligence`) shows real data only: inventory
totals (with/without agent, online, inactive), discovery counts (new / changed / unknown /
duplicate), inventory by OS, discovery-confidence distribution, inactive and newly-discovered assets
with a contextual **✨ Explain**, evidence-based recommendations, and recent AI investigations. A
licensing/lifecycle note states clearly that those facts are not collected and how to enable them.

## 4. Contextual AI actions

Reusing the Sprint 21 UX (a single sparkle action + the shared Copilot drawer, no duplicated chat):

| Context | Action |
|---|---|
| Asset | ✨ Explain |
| Dependency | ✨ Analyze |
| Lifecycle | ✨ Forecast |
| Relationship | ✨ Impact |
| License | ✨ Review |

Each opens the **Asset Copilot**, which reuses Quenyx AI conversations — every thread is a real
conversation openable in Quenyx AI.

## 5. Recommendations (always evidence-based)

Examples, each citing concrete evidence: investigate an **inactive** asset (agent offline), **enroll
an agent** for a monitored host with no agent (so hardware/inventory becomes available), **review
duplicate** assets (same address/name), and plan **hardware/capacity** action from Capacity Planning
runway. No recommendation is produced without evidence.

## 6. API

Workspace-scoped (required `workspace` UUID), UUID-only, behind `throttle:ai-workspace`:

- `GET  /api/qynasset/intelligence/overview`
- `POST /api/qynasset/intelligence/copilot`
- `GET  /api/qynasset/intelligence/recommendations`
- `POST /api/qynasset/intelligence/assets/{uuid}/explain`
- `POST /api/qynasset/intelligence/assets/{uuid}/dependencies`
- `POST /api/qynasset/intelligence/assets/{uuid}/lifecycle`
- `POST /api/qynasset/intelligence/assets/{uuid}/impact`
- `POST /api/qynasset/intelligence/licenses/review`

See API Reference (doc 08, §19).

## 7. Security & governance

Every request is workspace-aware, gated by the `qynasset` entitlement, RBAC (`accessAi`), and the
`can_use_ai` capability for AI actions; audited, provider-logged, conversation-logged, rate-limited,
and UUID-only. No data leakage across workspaces.

## 8. Enabling deeper intelligence

License and lifecycle-date intelligence require an inventory/license integration (e.g.
GLPI/FusionInventory). Until one is connected, those capabilities are exposed but report
"not collected" with the required integration — by design, so the gap is visible rather than faked.
