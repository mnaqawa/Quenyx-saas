# 17 — Implementation Guide

**Audience:** Implementation partners.
**Goal:** A repeatable path to onboard a customer onto Quenyx vOPS HUB.

---

## 1. Customer onboarding

1. Provision infrastructure (Ubuntu host(s), MySQL, Nginx) per Doc 10.
2. Deploy backend, frontend, gateway; run migrations + seeders.
3. Create the customer's **workspaces** (e.g. Production, Staging).
4. Create the admin user (rotate `SEED_ADMIN_PASSWORD`).
5. Invite the customer's users and assign roles.

## 2. Workspace setup

- Create one workspace per environment/business unit.
- Verify the workspace selector switches data correctly.
- Confirm audit logging is capturing actions (`/audit-logs`).

## 3. Module enablement

- Confirm entitlements (`/entitlements`, `/modules/access`).
- **QynSight** is visible by default. Use module **overrides** to grant additional modules where
  entitled — but **do not re‑enable hidden modules in the UI** without product sign‑off (current
  phase constraint).
- Confirm **QynShield** entitlement (`project.qynshield`) for compliance customers.

## 4. QynSight setup

1. Add **monitored targets/hosts**.
2. Install **agents** (generate enrollment token → download binary → enroll).
3. Configure **service definitions** and **alert rules** + monitoring profile.
4. Verify the **scheduler** and **queue worker** are running.
5. Confirm real‑time monitoring, infra map, and (optional) port scans populate.

## 5. QynShield setup

1. Ensure the QynShield entitlement is granted.
2. Seed the corpus: `ComplianceCorpusSeeder` + `compliance:seed-source-documents` for
   **NCA ECC‑2:2024**.
3. Verify counts: **5 domains, 108 controls, 108 requirements**, active **Revision v1**.
4. Validate corpus/graph/mapping/executive endpoints return data.

## 6. Evidence model onboarding

- Review evidence **types** and **statuses** with the customer.
- Establish how the customer will supply evidence per requirement (process + responsibilities).
- Run gap assessment to baseline current posture; review recommendations.

## 7. AI enablement checklist

- [ ] Decide whether to enable real‑model AI (default: **mock**). Most demos stay in mock mode.
- [ ] If enabling: set `AI_PROVIDER=openai`, `AI_ENABLED=true`, `OPENAI_API_KEY`, `OPENAI_MODEL`.
- [ ] Keep `AI_PROMPT_LOGGING_ENABLED=false` and `AI_CONVERSATION_PERSISTENCE_ENABLED=false` unless
      the customer explicitly consents.
- [ ] Keep `RAG_INDEX_TENANT_EVIDENCE=false`.
- [ ] Confirm `GET /api/ai/platform/capabilities` reflects the intended configuration.

## 8. Integration checklist

- [ ] Workspace integrations configured (`/integrations`).
- [ ] Billing integrations configured if used (`/billing/integrations`).
- [ ] `GATEWAY_INTERNAL_SECRET` set on both backend and gateway.
- [ ] `GATEWAY_BASE_URL` set for agent enrollment.
- [ ] CORS restricted to the customer's frontend origin.

## 9. Handover checklist

- [ ] Admin credentials rotated and stored securely.
- [ ] Scheduler + queue worker verified running.
- [ ] Backups scheduled (DB + storage).
- [ ] Health checks (`/api/health`, gateway `/health`) green.
- [ ] QynSight populating; QynShield corpus verified.
- [ ] AI flags reviewed and documented for the customer.
- [ ] Track‑B verification (Doc QA) run on the customer environment.
- [ ] Runbook (Doc 18) shared with the customer's ops team.
