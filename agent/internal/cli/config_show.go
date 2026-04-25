package cli

import (
	"encoding/json"
	"fmt"
	"os"

	"github.com/quenyx/agent/internal/config"
)

func runConfigShow(cfgPath string) error {
	cfg, err := config.Load(cfgPath)
	if err != nil {
		return fmt.Errorf("load config: %w", err)
	}

	path, _ := config.DefaultPath()
	if cfgPath != "" {
		path = cfgPath
	}
	fmt.Println("Config file:", path)
	fmt.Println()

	// Redact secret for display
	display := struct {
		PlatformURL      string   `json:"platform_url"`
		WorkspaceID      int      `json:"workspace_id"`
		AgentID          string   `json:"agent_id"`
		AgentSecret      string   `json:"agent_secret"`
		PrimaryProtocol  string   `json:"primary_protocol"`
		EnabledProtocols []string `json:"enabled_protocols"`
		Permissions      []string `json:"permissions"`
		PSAPPort         int      `json:"psap_port,omitempty"`
	}{
		PlatformURL:      cfg.PlatformURL,
		WorkspaceID:      cfg.WorkspaceID,
		AgentID:          cfg.AgentID,
		AgentSecret:      "(redacted)",
		PrimaryProtocol:  cfg.PrimaryProtocol,
		EnabledProtocols: cfg.EnabledProtocols,
		Permissions:      cfg.Permissions,
		PSAPPort:         0,
	}
	if cfg.PrimaryProtocol == "psap" || len(cfg.EnabledProtocols) > 0 {
		display.PSAPPort = config.PSAPPort
	}
	data, _ := json.MarshalIndent(display, "", "  ")
	os.Stdout.Write(data)
	fmt.Println()
	return nil
}
