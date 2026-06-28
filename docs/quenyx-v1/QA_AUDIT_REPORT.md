# Quenyx vOPS HUB — QA, Audit & Stabilization Report (v1)

> Track B of the Phase‑I closeout. Audit performed against the current codebase at the close of
> Sprint 19. **No new features** were added; this is audit, verification, and stabilization only.

---

## 1. Audit summary

| Area | Result |
|---|---|
| Backend boots / routes load | **PASS** — 261 API routes load, no collisions that break boot |
| Route registration integrity | **PASS** (1 low‑risk shadowed duplicate noted — `/ai/chat`) |
| PHP syntax (`php -l`) on platform code | **PASS** |
| Static scans (fake data, hardcoded models, direct OpenAI, id leaks) | **PASS** |
| AI safety defaults | **PASS** — AI off, prompt logging off, persistence off, RAG off, tenant embeddings off |
| Auth / RBAC / entitlements | **PASS** — sanctum + project membership + QynShield/QynSight gating present |
| Frontend module‑visibility flag | **PASS** — all modules registered; sidebar hidden behind flag; QynSight visible |
| Migrations apply | **DEFERRED to CI/CloudQuenyx** — no `pdo_mysql` driver locally |
| Seeders / corpus integrity counts | **DEFERRED to CI/CloudQuenyx** — requires DB |
| Frontend `npm run lint` / `build` | **DEFERRED to CI** — no Node/npm in audit sandbox |
| Gateway build/test | **DEFERRED to CI** — no Node/npm in audit sandbox |
| PHP test suite | **DEFERRED to CI/CloudQuenyx** — no `mbstring`/`pdo_mysql` locally |

**Overall:** all checks runnable in this environment **passed**. The remaining checks are blocked
only by **audit‑sandbox tooling limits** (documented below), not by code defects, and have exact
commands provided for CI / CloudQuenyx.

---

## 2. Environment used

**Audit sandbox (developer Windows host):**

- PHP **8.3.31** (CLI, ZTS).
- Loaded extensions: `bcmath, calendar, ctype, dom, filter, hash, iconv, json, libxml, mysqlnd, pcre, PDO, Phar, random, readline, Reflection, session, SimpleXML, SPL, standard, tokenizer, xml, xmlreader, xmlwriter, zlib`.
- **Missing locally:** `mbstring`, `pdo_mysql`, `openssl`, `curl`, `fileinfo`; **no `composer`**; **no Node/npm**.

**Consequences (environment, not code):**

- `php artisan about` aborts with `Call to undefined function Termwind\…\mb_strimwidth()` → caused by missing **`mbstring`**.
- `php artisan migrate:status` / `migrate` / `db:seed` / `test` fail with PDO `could not find driver` → missing **`pdo_mysql`**.
- `composer validate` not runnable → **composer** not installed in sandbox.
- `npm ci`, `npm run lint`, `npm run build`, gateway scripts not runnable → **Node** not installed in sandbox.

These must be run on CI or **CloudQuenyx** (which has the full PHP + MySQL + Node toolchain). Commands
are provided in §10.

---

## 3. Commands run (with results)

| Command | Result |
|---|---|
| `php artisan optimize:clear` | events cleared OK; `views` step failed `View path not found` (local view‑cache dir absent — env only) |
| `php artisan route:list --path=api` | **OK — 261 routes**, all controllers resolve, DI container builds |
| `php artisan route:list` (capabilities filter, prior runs) | `api/ai/platform/capabilities` present |
| `php -l` on all Sprint 17–19 files | **OK — no syntax errors** |
| `php -m` | confirmed extension set above |
| Grep: fake‑data markers in `backend/app` | only the **validator** that *forbids* them |
| Grep: OpenAI/model usage in `backend/app/Services/Compliance` | only provider class + docstrings (no direct calls) |
| Grep: OpenAI usage repo‑wide | isolated to provider classes + legacy `Services/OpenAI` knowledge‑base agent |
| Read `config/ai.php` | safety defaults confirmed |
| Read `frontend/src/constants/platformRegistry.ts` | module registry + hide flag confirmed |

---

## 4. Passed checks (detail)

### 4.1 Backend boot & routes (Audit Area 1, 5)
- App container builds and **all 261 API routes resolve** — no missing controllers, no broken
  service providers, no fatal config.
- Route groups present and correct: QynSight (`observe/*`), compliance corpus, AI context, graph,
  mappings, evidence, gap, recommendations, copilot, retrieval, RAG, executive, and
  `ai/platform/capabilities`.
- Both `projects/{project}/…` and `workspaces/{project}/…` aliases register cleanly.

### 4.2 AI safety (Audit Area 7)
Confirmed in `config/ai.php`:
- `ai.default = mock`; `ai.feature_flags.enabled = false` → **no real model call by default**.
- `prompt_logging = false`, `persist_conversations = false` (and Copilot‑specific equivalents false).
- `rag.enabled = false`, `rag.embeddings_enabled = false`, `rag.index_tenant_evidence = false`.
- Models come from **env only** — never hardcoded (`'model' => env('OPENAI_MODEL')`).
- Copilot is citation‑enforced and fail‑closed (Sprint 14+), reasoning is deterministic (Sprint 16),
  RAG falls back to deterministic retrieval when no vector provider (Sprint 17).
- No direct OpenAI/HTTP calls inside the QCIF AI core — embeddings/model access flow through
  `AiProviderRegistry` / provider classes only.

### 4.3 Auth / RBAC / entitlements (Audit Area 6)
- All tenant routes sit under the `auth:sanctum` group.
- Workspace/project membership enforced via `ProjectPolicy` (`authorize('view', $project)`).
- QynShield routes (corpus workspace, copilot, evidence, gap, recommendations, retrieval, RAG,
  executive) carry the `project.qynshield` middleware + per‑group throttles.
- QynSight `observe/*` routes carry `project.module:qynsight`.
- Agent ingestion routes use enrollment‑token/secret auth (not user auth) and are rate‑limited.

### 4.4 Static scans (Audit Area 11)
- **No** `lorem` / `sample control` / `fake control` / `faker` in production paths — the only hits
  are in `ComplianceCorpusValidator`, which **rejects** those markers (`FORBIDDEN_CODE_MARKERS`).
- **No** hardcoded GPT model strings in business services — models resolve from env.
- **No** direct OpenAI SDK/HTTP usage in `Services/Compliance/**` except the sanctioned provider
  class (`OpenAiVectorRetrievalProvider`) which itself routes through `AiProviderRegistry`.
- QCIF resources are **UUID‑only** (verified across Sprint 12–19 work).

### 4.5 Frontend module visibility (Audit Area 8 — static portion)
- `platformRegistry.ts` keeps **all** module definitions (`qynsight, qyncore, qynrun, qynbalance,
  qynsupport, qynintegrations, qynasset, qynknow, qynnotify, qynreact, qynshield, qynva`).
- Sidebar visibility is gated **separately** by `HIDE_NON_QYNSIGHT_MODULES = true` +
  `ACTIVE_MODULE_KEYS = ['qynsight']` via `isModuleTemporarilyVisible()`. **No module/billing/
  subscription data removed.** QynSight remains visible.

---

## 5. Failed checks
**None** among the checks runnable in the audit sandbox. All failures observed were tooling/extension
gaps in the sandbox (see §2), not code defects.

---

## 6. Bugs found

| # | Severity | Finding | Root cause |
|---|---|---|---|
| 1 | **Low** | Duplicate route registration for `POST /…/ai/chat` (both `AiAgentController@chat` and `Ai\AiOrchestrationController@chat`). | `routes/api.php` declares the legacy `ai/chat` route, then `routes/ai-orchestration.php` (required later) re‑declares the same path. Laravel keeps the **last** registration, so orchestration wins and the legacy mapping is dead/shadowed. |
| 2 | **Info** | `php artisan optimize:clear` `views` step errors locally (`View path not found`). | Missing local view‑cache directory in the audit sandbox. Does **not** occur in a normally bootstrapped deployment. |

No high or critical functional bugs were found.

---

## 7. Bugs fixed
- **None changed in code.** Per the Track‑B rule "do not change business logic unless fixing bugs,"
  finding #1 is a **shadowed duplicate** where the *intended* controller (orchestration) already
  wins — there is **no functional misbehavior** to fix, and the affected area is the **frozen
  legacy QynSight AI agent**. The safe, in‑scope action is to delete the dead legacy `ai/chat`
  line in a future cleanup PR; it is intentionally **not** modified here to avoid touching frozen
  code outside this audit's mandate. Finding #2 is environment‑only.

---

## 8. Remaining risks

| Risk | Severity | Mitigation / required action |
|---|---|---|
| DB migrations/seeders/corpus counts not verified in sandbox (no `pdo_mysql`). | **High (gating)** | Run §10 DB commands on CloudQuenyx and confirm domains=5, controls=108, requirements=108, active Revision v1, no orphans. |
| Frontend `lint`/`build` and gateway build/test not run in sandbox (no Node). | **Medium** | Run `npm ci && npm run lint && npm run build` (frontend + gateway) in CI. |
| PHP test suite not executed in sandbox (no `mbstring`/`pdo_mysql`). | **Medium** | Run `php artisan test` on CI/CloudQuenyx. |
| Shadowed duplicate `ai/chat` route (legacy). | **Low** | Remove the dead legacy declaration in a cleanup PR. |
| Local PHP missing `mbstring`/`openssl`/`curl`/`fileinfo`. | **Low (sandbox only)** | Production/CI PHP must include these (already standard on CloudQuenyx). |

---

## 9. Production readiness verdict

**Conditionally ready.** Everything verifiable in the audit sandbox **passed**: the backend boots,
all 261 routes resolve, AI is safe‑by‑default, RBAC/entitlements are enforced, and there is no fake
data or out‑of‑bounds AI access. **No critical or high code bugs were found.** The only **high‑risk**
items are *verification gaps* caused by sandbox tooling limits (DB, Node, PHP extensions) — they must
be closed by running §10 on CI/CloudQuenyx before Sprint 20.

---

## 10. Required actions before Sprint 20 (exact commands)

Run on **CloudQuenyx** (or CI) with the full toolchain:

```bash
# Backend boot & integrity
cd backend
composer validate
composer install --no-dev --optimize-autoloader      # or composer install
php artisan optimize:clear
php artisan about
php artisan route:list
php artisan migrate:status
php artisan migrate --force

# Seeders / corpus (Audit Area 3)
php artisan db:seed --class=ComplianceCorpusSeeder --force
php artisan compliance:seed-source-documents \
  database/corpus/nca/ecc-2-2024/source-documents.json \
  --framework=nca-ecc --release=2:2024

# QCIF integrity expectations (Audit Area 3/4): domains=5, controls=108, requirements=108,
# active Corpus Revision v1, import run linked, NO orphan controls/requirements, NO duplicate codes.

# Tests (Audit Area 10)
php artisan test
php artisan test --filter=Compliance
php artisan test --filter=Ai

# Frontend (Audit Area 8)
cd ../frontend
npm ci
npm run lint
npm run build

# Gateway (Audit Area 9)
cd ../gateway
npm ci
npm run build   # and npm test if defined

# Production smoke (Audit Area 13) — examples, adjust host/token
curl -s https://<host>/api/health
curl -s -H "Authorization: Bearer <token>" \
  https://<host>/api/workspaces/<id>/compliance/corpus/frameworks/nca-ecc/releases/2:2024/summary
curl -s -H "Authorization: Bearer <token>" -X POST \
  https://<host>/api/workspaces/<id>/compliance/copilot/message -d '{"message":"explain 1-1-1"}'
curl -s -H "Authorization: Bearer <token>" \
  https://<host>/api/workspaces/<id>/compliance/executive/dashboard
curl -s -H "Authorization: Bearer <token>" https://<host>/api/ai/platform/capabilities
curl -s -H "Authorization: Bearer <token>" -X POST \
  https://<host>/api/workspaces/<id>/compliance/retrieval/query -d '{"query":"access control"}'
```

**Gate:** Sprint 20 should begin only after the DB/corpus verification and the frontend/gateway/test
runs above complete green and the low‑risk `ai/chat` duplicate is cleaned up.
