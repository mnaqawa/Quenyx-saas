package diagnostics

import (
	"encoding/json"
	"runtime"

	"github.com/quenyx/agent/internal/config"
	"github.com/quenyx/agent/internal/plugins"
	"github.com/quenyx/agent/internal/policy"
	"github.com/quenyx/agent/internal/queue"
)

// BuildSupportBundle assembles a full support bundle for upload or CLI output.
func BuildSupportBundle(cfg *config.Config, mgr *plugins.Manager, q *queue.DiskQueue) map[string]interface{} {
	bundle := map[string]interface{}{
		"agent_version":    cfg.AgentVersion,
		"policy_version":   cfg.PolicyVersion,
		"platform_version": cfg.PlatformVersion,
		"config_version":   cfg.ConfigVersion,
		"lifecycle_status": cfg.LifecycleStatus,
		"policy_status":    policy.LocalPolicyStatus(cfg),
		"capabilities":     mgr.Capabilities(),
		"capability_hash":  policy.CapabilityHash(mgr.Capabilities()),
		"plugins":          mgr.HeartbeatPlugins(),
		"gateway_url":      cfg.PlatformURL,
		"environment": map[string]interface{}{
			"os":       runtime.GOOS,
			"arch":     runtime.GOARCH,
			"hostname": cfg.Hostname,
		},
	}
	if cfg.Diagnostics != nil {
		bundle["heartbeat_history"] = map[string]interface{}{
			"last_at":     cfg.Diagnostics.LastHeartbeatAt,
			"last_status": cfg.Diagnostics.LastHeartbeatStatus,
			"latency_ms":  cfg.Diagnostics.LastHeartbeatLatencyMs,
			"last_error":  cfg.Diagnostics.LastError,
		}
		bundle["health_summary"] = map[string]interface{}{
			"policy_status": cfg.Diagnostics.PolicyStatus,
			"last_error":    cfg.Diagnostics.LastError,
		}
	}
	if cfg.FailoverGateway != nil {
		bundle["gateway_connectivity"] = cfg.FailoverGateway
	}
	if cfg.RemoteSettings != nil {
		bundle["configuration"] = cfg.RemoteSettings
	}
	if q != nil {
		bundle["queue_stats"] = q.Stats().ToMap()
	}
	return bundle
}

// Marshal encodes bundle as JSON bytes.
func Marshal(bundle map[string]interface{}) ([]byte, error) {
	return json.Marshal(bundle)
}
