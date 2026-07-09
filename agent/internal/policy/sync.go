package policy

import (
	"fmt"

	"github.com/quenyx/agent/internal/config"
	"github.com/quenyx/agent/internal/plugins"
	"github.com/quenyx/agent/internal/version"
)

// Payload is the policy block returned by the platform on heartbeat.
type Payload struct {
	PolicyVersion        string   `json:"policy_version"`
	PlatformVersion      string   `json:"platform_version"`
	LatestAgentVersion   string   `json:"latest_agent_version"`
	SupportedAgentVersions []string `json:"supported_agent_versions"`
	PolicyStatus         string   `json:"policy_status"`
	CapabilityHash       string   `json:"capability_hash"`
}

// SyncResult describes local policy application outcome.
type SyncResult struct {
	Applied       bool
	PolicyStatus  string
	Error         string
	PolicyChanged bool
}

// Apply compares server policy with local state and updates config + plugin manager.
// Never enables dangerous plugins unless permissions already grant them.
func Apply(cfg *config.Config, mgr *plugins.Manager, server *Payload) SyncResult {
	result := SyncResult{PolicyStatus: "up_to_date"}

	if server == nil {
		result.PolicyStatus = cfg.Diagnostics.PolicyStatus
		if result.PolicyStatus == "" {
			result.PolicyStatus = "up_to_date"
		}
		return result
	}

	result.PolicyStatus = server.PolicyStatus
	if result.PolicyStatus == "" {
		result.PolicyStatus = "up_to_date"
	}

	if server.PlatformVersion != "" {
		cfg.PlatformVersion = server.PlatformVersion
	}

	policyChanged := server.PolicyVersion != "" && server.PolicyVersion != cfg.PolicyVersion
	if server.PolicyVersion != "" {
		if policyChanged {
			cfg.PolicyVersion = server.PolicyVersion
			result.PolicyChanged = true
		}
	}

	// Re-apply permissions-based plugin enablement (dangerous plugins stay gated).
	mgr.ApplyPermissions(cfg.Permissions)
	caps := mgr.Capabilities()
	cfg.Capabilities = caps
	cfg.CapabilityHash = CapabilityHash(caps)
	cfg.PluginVersions = mgr.PluginVersions()

	if server.CapabilityHash != "" && server.CapabilityHash != cfg.CapabilityHash {
		result.PolicyStatus = "policy_sync_required"
		result.Error = "capability hash mismatch with platform"
		cfg.LifecycleStatus = "policy_sync_pending"
		return result
	}

	cfg.LifecycleStatus = "online"
	result.Applied = true
	return result
}

// LocalPolicyStatus derives status when server does not send policy block.
func LocalPolicyStatus(cfg *config.Config) string {
	if cfg.LifecycleStatus == "policy_sync_pending" {
		return "policy_sync_required"
	}
	if cfg.AgentVersion != "" && cfg.AgentVersion != version.Agent {
		return "upgrade_available"
	}
	return "up_to_date"
}

// FormatPolicyError returns a human-readable sync failure reason.
func FormatPolicyError(err error) string {
	if err == nil {
		return ""
	}
	return fmt.Sprintf("%v", err)
}
