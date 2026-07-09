package cli

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"runtime"

	"github.com/quenyx/agent/internal/config"
	"github.com/quenyx/agent/internal/version"
)

func runEnroll(platformURL string, workspaceID int, token string) error {
	baseURL, err := url.JoinPath(platformURL, "/v1/agents/register")
	if err != nil {
		return fmt.Errorf("invalid URL: %w", err)
	}

	hostname, _ := os.Hostname()
	if hostname == "" {
		hostname = "unknown"
	}
	privateIP := getPrivateIP()
	publicIP := getPublicIP()

	body := map[string]interface{}{
		"workspace_id":    workspaceID,
		"token":           token,
		"hostname":        hostname,
		"private_ip":      privateIP,
		"public_ip":       publicIP,
		"os":              runtime.GOOS,
		"arch":            runtime.GOARCH,
		"agent_version":     version.Agent,
		"primary_protocol": "qag",
		"enabled_protocols": []string{"qag"},
		"permissions":     []string{"system_metrics", "inventory", "network", "filesystem"},
	}

	jsonBody, _ := json.Marshal(body)
	req, err := http.NewRequest("POST", baseURL, bytes.NewReader(jsonBody))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Quenyx-Agent-Version", version.Agent)

	client := &http.Client{}
	resp, err := client.Do(req)
	if err != nil {
		return fmt.Errorf("request failed: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("registration failed: HTTP %d", resp.StatusCode)
	}

	var result struct {
		Success bool `json:"success"`
		Data    struct {
			AgentID          string   `json:"agent_id"`
			AgentSecret      string   `json:"agent_secret"`
			PrimaryProtocol  string   `json:"primary_protocol"`
			EnabledProtocols []string `json:"enabled_protocols"`
			Permissions      []string `json:"permissions"`
		} `json:"data"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&result); err != nil {
		return fmt.Errorf("decode response: %w", err)
	}
	if !result.Success || result.Data.AgentID == "" || result.Data.AgentSecret == "" {
		return fmt.Errorf("invalid response: missing agent_id or agent_secret")
	}

	cfg := config.Config{
		PlatformURL:      platformURL,
		WorkspaceID:      workspaceID,
		AgentID:          result.Data.AgentID,
		AgentSecret:      result.Data.AgentSecret,
		PrimaryProtocol:  result.Data.PrimaryProtocol,
		EnabledProtocols: result.Data.EnabledProtocols,
		Permissions:      result.Data.Permissions,
		Hostname:         hostname,
		AgentVersion:     version.Agent,
		PolicyVersion:    version.DefaultPolicy,
		PlatformVersion:  version.DefaultPlatform,
		LifecycleStatus:  "online",
	}
	if cfg.PrimaryProtocol == "" {
		cfg.PrimaryProtocol = "qag"
	}

	cfgPath, err := config.DefaultPath()
	if err != nil {
		return err
	}
	dir := filepath.Dir(cfgPath)
	if err := os.MkdirAll(dir, 0700); err != nil {
		return fmt.Errorf("create config dir: %w", err)
	}

	if err := config.Save(&cfg, cfgPath); err != nil {
		return fmt.Errorf("write config: %w", err)
	}

	fmt.Printf("Enrolled successfully. Config saved to %s\n", cfgPath)
	switch runtime.GOOS {
	case "windows":
		fmt.Println("To start the agent, run: .\\quenyx-agent.exe run")
	default:
		fmt.Println("To start the agent, run: ./quenyx-agent run  (or add it to PATH and run: quenyx-agent run)")
	}
	return nil
}
