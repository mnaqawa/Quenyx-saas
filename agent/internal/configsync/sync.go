package configsync

import (
	"github.com/quenyx/agent/internal/config"
)

// RemoteSettings is the platform configuration block from heartbeat.
type RemoteSettings struct {
	Version  string                 `json:"version"`
	Settings map[string]interface{} `json:"settings"`
}

// Apply merges remote configuration into local runtime settings.
// Returns true when version changed.
func Apply(cfg *config.Config, remote *RemoteSettings) bool {
	if remote == nil || remote.Version == "" {
		return false
	}
	if cfg.ConfigVersion == remote.Version {
		return false
	}
	cfg.ConfigVersion = remote.Version
	if cfg.RemoteSettings == nil {
		cfg.RemoteSettings = map[string]interface{}{}
	}
	for k, v := range remote.Settings {
		cfg.RemoteSettings[k] = v
	}
	return true
}

// HeartbeatInterval returns configured heartbeat interval or defaultSeconds.
func HeartbeatInterval(cfg *config.Config, defaultSeconds int) int {
	return intSetting(cfg, "heartbeat_interval_seconds", defaultSeconds)
}

// TelemetryInterval returns configured telemetry interval or defaultSeconds.
func TelemetryInterval(cfg *config.Config, defaultSeconds int) int {
	return intSetting(cfg, "telemetry_interval_seconds", defaultSeconds)
}

// InventoryInterval returns configured inventory interval or defaultSeconds.
func InventoryInterval(cfg *config.Config, defaultSeconds int) int {
	return intSetting(cfg, "inventory_interval_seconds", defaultSeconds)
}

func intSetting(cfg *config.Config, key string, def int) int {
	if cfg.RemoteSettings == nil {
		return def
	}
	v, ok := cfg.RemoteSettings[key]
	if !ok {
		return def
	}
	switch n := v.(type) {
	case float64:
		if n > 0 {
			return int(n)
		}
	case int:
		if n > 0 {
			return n
		}
	}
	return def
}
