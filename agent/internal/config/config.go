package config

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"runtime"

	"github.com/quenyx/agent/internal/version"
)

// PSAPPort is the default TCP port for Quenyx Agent Protocol (when primary_protocol is psap).
const PSAPPort = 9444

// FailoverGateway is a temporary alternate QAG endpoint returned by the platform.
type FailoverGateway struct {
	GatewayUUID string `json:"gateway_uuid,omitempty"`
	EndpointURL string `json:"endpoint_url,omitempty"`
	Region      string `json:"region,omitempty"`
}

// DiagnosticsState is persisted agent-local operational diagnostics.
type DiagnosticsState struct {
	LastHeartbeatAt        string  `json:"last_heartbeat_at,omitempty"`
	LastHeartbeatStatus    string  `json:"last_heartbeat_status,omitempty"`
	LastHeartbeatLatencyMs float64 `json:"last_heartbeat_latency_ms,omitempty"`
	PolicyStatus           string  `json:"policy_status,omitempty"`
	LastError              string  `json:"last_error,omitempty"`
}

type Config struct {
	PlatformURL      string            `json:"platform_url"`
	WorkspaceID      int               `json:"workspace_id"`
	AgentID          string            `json:"agent_id"`
	AgentSecret      string            `json:"agent_secret"`
	PrimaryProtocol  string            `json:"primary_protocol,omitempty"`
	EnabledProtocols []string          `json:"enabled_protocols,omitempty"`
	Permissions      []string          `json:"permissions,omitempty"`
	Capabilities     []string          `json:"capabilities,omitempty"`
	Hostname         string            `json:"hostname,omitempty"`
	AgentVersion     string            `json:"agent_version,omitempty"`
	PolicyVersion    string            `json:"policy_version,omitempty"`
	PlatformVersion  string            `json:"platform_version,omitempty"`
	CapabilityHash   string            `json:"capability_hash,omitempty"`
	PluginVersions   map[string]string `json:"plugin_versions,omitempty"`
	FailoverGateway  *FailoverGateway  `json:"failover_gateway,omitempty"`
	LifecycleStatus  string            `json:"lifecycle_status,omitempty"`
	ConfigVersion    string            `json:"config_version,omitempty"`
	RemoteSettings   map[string]interface{} `json:"remote_settings,omitempty"`
	UpdateChannel    string            `json:"update_channel,omitempty"`
	Diagnostics      *DiagnosticsState `json:"diagnostics,omitempty"`
}

func DefaultPath() (string, error) {
	var dir string
	switch runtime.GOOS {
	case "windows":
		dir = os.Getenv("APPDATA")
		if dir == "" {
			dir = filepath.Join(os.Getenv("USERPROFILE"), "AppData", "Roaming")
		}
	default:
		dir = os.Getenv("HOME")
		if dir == "" {
			return "", fmt.Errorf("HOME not set")
		}
		dir = filepath.Join(dir, ".config")
	}
	return filepath.Join(dir, "quenyx", "agent.json"), nil
}

func Load(path string) (*Config, error) {
	if path == "" {
		var err error
		path, err = DefaultPath()
		if err != nil {
			return nil, err
		}
	}
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("read config: %w", err)
	}
	var cfg Config
	if err := json.Unmarshal(data, &cfg); err != nil {
		return nil, fmt.Errorf("parse config: %w", err)
	}
	if cfg.PlatformURL == "" || cfg.AgentID == "" || cfg.AgentSecret == "" {
		return nil, fmt.Errorf("invalid config: missing platform_url, agent_id, or agent_secret")
	}
	cfg.applyDefaults()
	return &cfg, nil
}

func (c *Config) applyDefaults() {
	if c.PrimaryProtocol == "" {
		c.PrimaryProtocol = "http_api"
	}
	if c.AgentVersion == "" {
		c.AgentVersion = version.Agent
	}
	if c.PolicyVersion == "" {
		c.PolicyVersion = version.DefaultPolicy
	}
	if c.PlatformVersion == "" {
		c.PlatformVersion = version.DefaultPlatform
	}
	if c.PluginVersions == nil {
		c.PluginVersions = map[string]string{}
	}
	if c.Diagnostics == nil {
		c.Diagnostics = &DiagnosticsState{}
	}
	if c.LifecycleStatus == "" {
		c.LifecycleStatus = "online"
	}
}

// Save writes config to path (or default path when empty).
func Save(cfg *Config, path string) error {
	if path == "" {
		var err error
		path, err = DefaultPath()
		if err != nil {
			return err
		}
	}
	cfg.applyDefaults()
	dir := filepath.Dir(path)
	if err := os.MkdirAll(dir, 0700); err != nil {
		return fmt.Errorf("create config dir: %w", err)
	}
	data, err := json.MarshalIndent(cfg, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(path, data, 0600)
}
