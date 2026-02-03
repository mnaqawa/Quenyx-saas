---
name: node-gateway-microservices
description: Expert guidance for Node.js gateways and microservices: internal auth headers, secure routing, service adapters (Nagios, Wazuh, etc.), contract design, schema validation, and resilient communication (timeouts, retries, circuit breakers). Use when building or modifying Node.js gateways, internal APIs, engine adapters, or when the user asks about gateway security, service integration, or resilient HTTP patterns.
---

# Node.js Gateway & Microservices Expert

## Internal Auth Headers

- **Purpose**: Protect internal service-to-service routes from public access. Do not rely on user JWT/cookies for internal calls.
- **Pattern**: Dedicated secret header (e.g. `x-internal-secret`) from env (e.g. `GATEWAY_INTERNAL_SECRET`). Compare with constant-time comparison to avoid timing leaks.
- **Rules**:
  - Mount internal routes under a distinct path prefix (e.g. `/internal/engines`).
  - Apply a single middleware that checks the header before any internal handler; return 401 with a generic message on failure.
  - Never forward `x-internal-secret` to the browser or log it; in logs, mention only presence/absence or a hash.
- **Caller**: Backend or other services calling the gateway must send the same secret in `x-internal-secret`. Document the header in API contracts.

## Secure Routing

- **Separation**: Internal routes (engine adapters, admin) must not be reachable without internal auth. Public routes (e.g. `/api`, `/health`) go through entitlement/auth as needed; do not expose internal paths there.
- **Path discipline**: Use a single prefix for internal APIs (e.g. `/internal/engines`). Avoid overlapping prefixes so middleware order is clear.
- **Forwarding to backend**: When proxying to a backend, forward only the headers that are required for auth and content (e.g. `Authorization`, `Cookie`, `Content-Type`, `Accept`). Consider an allowlist to avoid forwarding internal or sensitive headers.
- **Sensitive data in logs**: Do not log full `Authorization` or internal secret; log only status, path, duration, and optionally a non-reversible hash of the token for correlation.

## Service Adapters (Nagios, Wazuh, etc.)

- **Adapter role**: The gateway translates between internal HTTP contracts and each engine’s API (files, CGI, REST, etc.). Keep engine-specific logic in dedicated modules (e.g. `engines/nagios.ts`, `engines/wazuh.ts`).
- **Identity**: Use deterministic identifiers (IDs, keys) for entities (hosts, services, workspaces), not display names, in URLs and payloads. This keeps sync and joins stable.
- **Config and state**: Read engine config/base URL from env or a small config layer. Avoid hardcoding paths or hostnames. For file-based engines (e.g. Nagios), define a single config root and workspace-specific includes so writes are predictable and auditable.
- **Errors**: Map engine errors to stable HTTP status codes and a consistent JSON shape (e.g. `{ success: false, message: string, ... }`). Do not leak internal paths or stack traces in production responses.

## Contract Design

- **Explicit contracts**: Document request method, path, headers, and body/query for each internal and public endpoint. Prefer a single doc (e.g. `api-contracts.md` or OpenAPI) that both gateway and backend follow.
- **Versioning**: When changing payloads, support a transition path: either a new path/version (e.g. `/v2/...`) or backward-compatible fields. Do not change semantics of existing fields silently.
- **Idempotency**: For write operations (config write, reload), design so that repeating the same call with the same inputs is safe. Use PUT for full replacement where appropriate; document idempotency in the contract.
- **Request context**: Pass context (e.g. `x-workspace-id`, `x-request-id`) via headers so adapters and backend can scope and trace requests.

## Schema Validation

- **Validate early**: Validate request body, query, and critical headers (e.g. `x-workspace-id`) at the gateway before calling adapters or backend. Return 400 with clear messages for invalid input.
- **Libraries**: Use a schema library (e.g. Zod, Joi) to define request/response shapes and reuse types in TypeScript. Validate size limits (e.g. max body length) before parsing.
- **Consistency**: Use the same schema for documentation and runtime validation so the contract and implementation stay in sync.

## Resilient Communication

- **Timeouts**: Set timeouts on every outbound HTTP call (to backend, to engine CGIs, etc.). Prefer a single configured value (e.g. 60s for proxy, 30s for engine) so slow dependencies do not hang the gateway.
- **Retries**: For transient failures (network errors, 5xx), use a limited retry policy (e.g. 2–3 retries with backoff). Do not retry on 4xx (client error) or for non-idempotent methods unless the contract explicitly allows it.
- **Circuit breaker**: For calls to flaky or external services, consider a circuit breaker (e.g. open after N failures, half-open to probe). When open, fail fast and return 503 or a clear error so callers can back off.
- **Connection reuse**: Use a keep-alive HTTP agent with bounded sockets (e.g. `maxSockets`, `maxFreeSockets`) when proxying or calling backend to avoid connection churn and exhaustion.

## Checklist for New Internal Endpoints

- [ ] Route lives under the internal prefix and is protected by internal auth middleware.
- [ ] Request body/query/headers validated; invalid input returns 400 with a clear message.
- [ ] Response shape matches the documented contract (e.g. `{ success, data?, message? }`).
- [ ] Outbound calls to engine or backend have timeouts and, where appropriate, retries or circuit breaker.
- [ ] Errors from downstream are mapped to stable HTTP status and JSON; no sensitive details in production.
- [ ] Logs do not include secrets or full auth tokens; use hashes or “present/absent” only.
