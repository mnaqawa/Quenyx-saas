package config

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"runtime"
)

type Config struct {
	PlatformURL string `json:"platform_url"`
	WorkspaceID int    `json:"workspace_id"`
	AgentID     string `json:"agent_id"`
	AgentSecret string `json:"agent_secret"`
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
	return filepath.Join(dir, "portshield", "agent.json"), nil
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
	return &cfg, nil
}
