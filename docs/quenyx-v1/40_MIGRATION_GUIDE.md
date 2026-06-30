# Migration Guide — Upgrading to Quenyx vOPS HUB v1.0.0

> **Quenyx vOPS HUB — Document Metadata**
>
> | Field | Value |
> |---|---|
> | Document Version | 1.0 |
> | Software Version | v1.0.0 |
> | Applies To | Upgrade from v1.0.0 RC1 (Sprint 24) → v1.0.0 (Sprint 25) |
> | Classification | Internal |
> | Owner | Platform Engineering / SRE |
> | Status | Released |
> | Document Type | Migration guide |

## Summary

Sprint 25 is **additive**. It introduces new shared services, two new module surfaces (QynVA, QynBalance),
and enables the full navigation. There are **no breaking changes** to Sprint 20–24 behavior and **no
destructive migrations**. Upgrading is low-risk.

## Pre-upgrade checklist

- [ ] Back up the database (standard pre-deploy snapshot).
- [ ] Confirm you are on v1.0.0 RC1 (Sprint 24) and migrations are current (`php artisan migrate:status`).
- [ ] Note your current AI provider configuration (unchanged by this release).

## Upgrade steps

```bash
# 1. Pull the v1.0.0 release
git fetch --tags && git checkout v1.0.0

# 2. Backend dependencies + migrations (idempotent; Sprint 24 collaboration tables are guarded)
cd backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force

# 3. Clear & rebuild caches
php artisan config:clear && php artisan cache:clear && php artisan route:clear
php artisan config:cache && php artisan route:cache

# 4. Frontend
cd ../frontend
npm ci
npm run build
```

## Configuration changes

### New (optional) — QynBalance pricing — `config/cost.php`
QynBalance works without any pricing config (it reports real **counts** and "pricing unavailable"). To
unlock monetary estimates, set real rates from your own contracts/cloud bills:

```
COST_CURRENCY=USD
COST_HOST_PER_MONTH=...      COST_AGENT_PER_MONTH=...     COST_SERVICE_PER_MONTH=...
COST_LICENSE_PER_SEAT=...    COST_AUTOMATION_RUN_MINUTE=...
COST_MONTHLY_BUDGET=...      COST_IDLE_AGENT_HOURS=72
```

> **Do not invent rates.** Leave any rate unset if you don't have a real figure — QynBalance will remain
> honest rather than fabricate.

### Unchanged
AI provider settings, AI workspace flags, entitlements/plans, and all Sprint 20–24 env keys are unchanged.

## Entitlements

QynVA and QynBalance are `ai_candidate => true` and ship enabled in the navigation. They are gated, like
every module, by **plan/workspace entitlements** and AI RBAC (`accessAi`, `can_use_ai`, `administerAi`).
Add `qynva` / `qynbalance` to the relevant plan's `modules_allowed` to expose them to a workspace.

## Navigation change

The temporary sidebar feature flag is removed; all business modules are visible. `qyncore` and
`qynintegrations` are platform-only and intentionally not shown as customer modules. No action needed.

## Post-upgrade verification

- [ ] `GET /api/qynva/operator/capabilities` returns the discovered module catalog.
- [ ] `GET /api/qynva/health` (admin) reports `operational` (or explains any `degraded` area).
- [ ] `GET /api/qynbalance/cost/overview` returns counts (+ monetary only if rates configured).
- [ ] Sidebar shows QynSight, QynAsset, QynRun, QynReact, QynKnow, QynSupport, QynNotify, QynShield,
      QynBalance, QynVA.
- [ ] Sprint 20–24 surfaces behave exactly as before (no regressions).

## Rollback

Sprint 25 adds no destructive migrations. To roll back, redeploy the previous tag and restore caches; no
data migration reversal is required.
