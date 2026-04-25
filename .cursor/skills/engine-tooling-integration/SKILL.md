---
name: engine-tooling-integration
description: Deep practical integration knowledge for Wazuh SIEM pipelines and alerts, SonarQube quality gates, Nagios check commands and object lifecycle, Prometheus metrics model, Grafana dashboards, FreeIPA/AD IAM patterns, GLPI/FusionInventory asset discovery and CMDB workflows, and Kaspersky EDR/KSC telemetry and policy integration. Emphasizes stable identifiers, correct mapping, and production readiness. Use when designing or implementing integrations with these tools, ingestion pipelines, alerting, quality gates, monitoring, IAM, asset discovery, or EDR.
---

# Engine Tooling Integration

Apply this skill when integrating Quenyx (or any platform) with Wazuh, SonarQube, Nagios, Prometheus, Grafana, FreeIPA/AD, GLPI/FusionInventory, or Kaspersky EDR/KSC. Focus: **stable identifiers**, **correct mapping**, **production readiness**.

## Cross-Cutting Principles

- **Stable identifiers**: Prefer IDs, keys, and deterministic names (e.g. rule ID, host key, metric labels) over display names for joins, sync, and APIs. Display names can change; IDs should not.
- **Mapping discipline**: Document source → normalized field mapping. Validate and log mapping failures; do not silently drop or misattribute data.
- **Production readiness**: Timeouts, retries, idempotency where applicable, structured logs around ingest/transform, and no silent fallbacks that hide backend issues.

---

## Wazuh SIEM

- **Pipelines**: Alerts flow from agents → manager → indexer (optional) → API/dashboards. For integration, use the Wazuh API or direct index/alert ingestion; avoid relying on file tails for production.
- **Stable identifiers**:
  - **Rule**: `rule.id` (numeric) and `rule.groups`; use `rule.id` for dedup and correlation.
  - **Agent**: `agent.id` (numeric) and `agent.name`; use `agent.id` for joins; `agent.name` can change.
  - **Alert**: `id` (if present) or composite `timestamp + rule.id + agent.id` for idempotent ingest.
- **Mapping**: Map `rule.level`, `rule.groups`, `agent.id`, `manager.name`, `data.*` to your schema. Normalize severity from `rule.level` bands; keep raw level for audit.
- **Production**: Use API auth (JWT or API key), pagination for large result sets, and backpressure when writing to your store. Log ingest counts and mapping errors by `rule.id`/`agent.id`.

---

## SonarQube Quality Gates

- **Concepts**: Projects (key = stable ID), quality gates (name + conditions), conditions (metric key, op, threshold). Use **project key** (and branch key if branching) for all external references.
- **Stable identifiers**:
  - **Project**: `project.key` (e.g. `myapp`); never use project name for APIs or storage.
  - **Quality gate**: Name is mutable; if you need a stable ref, maintain a mapping (e.g. name → internal ID) or use the single default gate per project.
  - **Metric**: Metric keys (e.g. `bugs`, `vulnerabilities`, `code_smells`, `coverage`) are stable; use them in conditions and in your schema.
- **Mapping**: Map project key + branch → quality gate status and condition results. Store metric keys and threshold comparisons; avoid storing only “passed/failed” without metric details for auditing.
- **Production**: Use project/search and project/status APIs with project key. Cache project list and gate status with TTL; use webhooks for “on change” if available to reduce polling.

---

## Nagios

- **Object model**: Hosts and services are the core; host_name + service_description (or host_name for host checks) form the natural key. Config is file-based; state is in status.dat or via CGIs/API.
- **Check commands**: Defined in config with `command_name` and `command_line`; arguments are passed as `$ARGn$`. Identity for a check = host + service (or host for host checks); use these for any external mapping.
- **Stable identifiers**:
  - **Host**: `host_name` (config key); use it everywhere. Do not use `alias` or display name for joins.
  - **Service**: `host_name` + `service_description`; composite key is stable.
  - **Object lifecycle**: Define → Validate (nagios -v) → Reload (SIGHUP or external reload). Changes take effect only after reload; ensure your integration waits for reload or polls state after config write.
- **Mapping**: Map host_name and (host_name, service_description) to your DB/workspace. Map state (0/1/2/3) and output to a normalized status and message; keep raw output for debugging.
- **Production**: Idempotent config writes (full replace or well-defined merge), validate before reload, timeout on CGIs/API. Log which host/service set was written and reload result.

---

## Prometheus Metrics Model

- **Model**: Metric name + labels → time series. Only add labels that have bounded cardinality; avoid user IDs or unbounded values as label values.
- **Stable identifiers**: Metric name + label set (e.g. `job`, `instance`, `env`) identify a series. Use consistent label names across scrapes (e.g. `job`, `instance`, `namespace`) for joining and alerting.
- **Mapping**: Define a small, fixed label set for your app (e.g. `job`, `instance`, `workspace_id`). Map external systems’ “host” or “service” to these labels; do not create new labels per entity unless cardinality is controlled.
- **Production**: Scrape timeouts, honor `Accept-Encoding: gzip`, avoid high cardinality (no per-request or per-user labels on core metrics). Use recording rules for heavy aggregations; reference the same label names in alerts and Grafana.

---

## Grafana Dashboards

- **Stable identifiers**:
  - **Dashboard**: `uid` (string) is the stable ID; use it in links, API, and version control. Avoid using slug or numeric ID for cross-system refs.
  - **Datasource**: `uid` for referencing in dashboards and provisioning; name can change.
  - **Folder**: Folder UID for hierarchy; use UID in provisioning and API.
- **Mapping**: When provisioning or syncing dashboards, set `uid` explicitly and keep a mapping (e.g. workspace_id → dashboard_uid). Use variables (e.g. `$workspace`) for multi-tenant dashboards with a single definition.
- **Production**: Prefer provisioning (files or API) over manual edits for reproducibility. Version dashboard JSON; use dashboard `version` and avoid overwriting user edits if you support both provisioned and ad-hoc dashboards.

---

## FreeIPA / Microsoft AD IAM

- **Concepts**: Users, groups, roles; identity source is authoritative. Use immutable identifiers for sync and RBAC.
- **Stable identifiers**:
  - **User**: Object GUID (AD) or `uid` (IPA); use these for joins and permission checks. UserPrincipalName / login can change.
  - **Group**: Group GUID or IPA cn/object ID; use for role mapping and membership.
  - **Role**: Map to a single canonical ID (e.g. role UUID or internal role key) in your app; do not key off display name.
- **Mapping**: Map directory groups/roles to application roles by GUID/ID; store the mapping in config or DB. On sync, resolve membership by ID and update your RBAC store; log add/remove by ID for audit.
- **Production**: Use service accounts with minimal read scope, paginate large directory reads, cache membership with short TTL or event-driven refresh. Handle LDAP timeouts and partial results; do not assume full sync in one call.

---

## GLPI + FusionInventory Asset Discovery

- **Concepts**: GLPI = CMDB/ticketing; FusionInventory = discovery and inventory. Assets are identified by type + GLPI internal ID; discovery uses agent UUID and inventory rules.
- **Stable identifiers**:
  - **Computer/asset**: GLPI entity ID (e.g. `Computer.id`); use for links and reconciliation. Name and serial are for display and matching.
  - **Agent**: FusionInventory agent identifier / UUID for correlating inventories to the same endpoint.
  - **Inventory**: Use a composite of (agent, inventory date or version) for idempotent ingest of inventory payloads.
- **Mapping**: Map GLPI asset type + ID to your schema; map discovery fields (name, serial, UUID, MAC, etc.) to your normalized asset model. Reconciliation: match on serial/UUID/MAC, then update by GLPI ID.
- **Production**: Use GLPI REST/API with API token; respect rate limits. For bulk import, use batch endpoints or queues. Log reconciliation decisions (matched by X, created/updated ID Y).

---

## Kaspersky EDR / KSC Telemetry and Policy

- **Concepts**: KSC = central management; EDR/agents report events and policy state. Identity = machine/host ID and policy ID in Kaspersky terms.
- **Stable identifiers**:
  - **Host/machine**: Use KSC host ID or persistent machine GUID for correlation; do not key off hostname or IP.
  - **Policy**: Policy ID (not name) for linking policies to hosts and to your policy store.
  - **Events**: Use event ID or (timestamp + host ID + event type) for dedup and idempotent ingest.
- **Mapping**: Map Kaspersky severity and event types to your normalized event schema; map policy ID and name to your policy entity. Keep raw event payload for forensics.
- **Production**: Use KSC API or approved integration channel; authenticate with service credentials. Throttle and batch when pulling events; use webhooks/callbacks if available. Log ingest volume and mapping failures by host ID and event type.

---

## Integration Checklist (Any Engine)

- [ ] All external entity references use stable IDs (not display names).
- [ ] Source → normalized field mapping is documented and validated; failures are logged with source IDs.
- [ ] Timeouts and retries (where appropriate) are set; no silent swallow of errors.
- [ ] Idempotency considered for ingest (e.g. by event/alert ID or composite key).
- [ ] Structured logs include relevant IDs (rule.id, agent.id, host_name, project.key, etc.) for debugging and audit.
