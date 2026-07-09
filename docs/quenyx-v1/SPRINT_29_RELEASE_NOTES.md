# Release Notes — Sprint 29: Platform Agent Operational Maturity

## Summary

Final Platform Agent sprint before enterprise customer onboarding. Adds self-update framework, centralized configuration, health scoring, certificate lifecycle (mTLS-ready), offline queue/replay, diagnostics bundles, fleet operations APIs, and AI fleet intelligence — all backward compatible.

## Backend

- Migration `2026_07_09_180000_platform_agent_operational_maturity`
- Services: `AgentUpdateService`, `AgentHealthScoringService`, `AgentCertificateService`, `AgentConfigurationService`, `AgentOfflineQueueService`, `AgentDiagnosticsService`, `FleetOperationsService`, `FleetIntelligenceService`
- Heartbeat response extended with `configuration`, `update`, `certificate`, `health` blocks
- New platform APIs: `/health`, `/updates`, `/configuration`, `/certificates`, `/queue`, `/fleet/summary`

## Agent (Go)

- Offline disk queue with compression and deduplication
- Policy-gated self-update with checksum verification
- Remote configuration sync
- Enhanced diagnostics support bundle

## Frontend

- Fleet dashboard: health distribution, top failing plugins, disconnected agents via fleet summary API

## Configuration

- `AGENT_MTLS_ENABLED` (default: false)
- `agent.configuration.defaults`, `agent.health.weights`, `agent.offline_queue.*`

## Tests

- `PlatformAgentOperationalMaturityTest` — upgrades, health, config, queue, APIs, AI context, diagnostics
