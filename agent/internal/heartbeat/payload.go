package heartbeat

import (
	"encoding/json"
	"time"

	"github.com/quenyx/agent/internal/config"
	"github.com/quenyx/agent/internal/plugins"
	"github.com/quenyx/agent/internal/policy"
	"github.com/quenyx/agent/internal/resources"
	"github.com/quenyx/agent/internal/version"
)

// BuildPayload constructs the Sprint 28 fleet heartbeat body.
func BuildPayload(cfg *config.Config, mgr *plugins.Manager, privateIP, publicIP string, bytesSent, bytesReceived uint64) map[string]interface{} {
	caps := mgr.Capabilities()
	hash := policy.CapabilityHash(caps)

	body := map[string]interface{}{
		"agent_version":     version.Agent,
		"platform_version":  cfg.PlatformVersion,
		"policy_version":    cfg.PolicyVersion,
		"capability_hash":   hash,
		"plugin_versions":   mgr.PluginVersions(),
		"capabilities":      caps,
		"lifecycle_status":  cfg.LifecycleStatus,
		"managed_resources": []map[string]interface{}{resources.LocalHost(privateIP, publicIP)},
		"plugins":           mgr.HeartbeatPlugins(),
	}

	if privateIP != "" {
		body["private_ip"] = privateIP
	}
	if publicIP != "" {
		body["public_ip"] = publicIP
	}
	if bytesSent > 0 {
		body["bytes_sent"] = bytesSent
	}
	if bytesReceived > 0 {
		body["bytes_received"] = bytesReceived
	}
	if cfg.Diagnostics != nil && cfg.Diagnostics.LastError != "" {
		body["last_error"] = cfg.Diagnostics.LastError
	}

	return body
}

// Response is the platform heartbeat JSON envelope.
type Response struct {
	Success bool `json:"success"`
	Data    struct {
		Status          string                 `json:"status"`
		Policy          *policy.Payload        `json:"policy"`
		FailoverGateway *config.FailoverGateway `json:"failover_gateway"`
	} `json:"data"`
}

// ParseResponse decodes heartbeat response; returns nil policy on empty/legacy responses.
func ParseResponse(body []byte) (*Response, error) {
	if len(body) == 0 {
		return nil, nil
	}
	var resp Response
	if err := json.Unmarshal(body, &resp); err != nil {
		return nil, err
	}
	return &resp, nil
}

// UpdateDiagnostics records heartbeat outcome on config.
func UpdateDiagnostics(cfg *config.Config, status string, latency time.Duration, errMsg string) {
	if cfg.Diagnostics == nil {
		cfg.Diagnostics = &config.DiagnosticsState{}
	}
	cfg.Diagnostics.LastHeartbeatAt = time.Now().UTC().Format(time.RFC3339)
	cfg.Diagnostics.LastHeartbeatStatus = status
	cfg.Diagnostics.LastHeartbeatLatencyMs = float64(latency.Milliseconds())
	if errMsg != "" {
		cfg.Diagnostics.LastError = errMsg
	} else if status == "ok" {
		cfg.Diagnostics.LastError = ""
	}
}
