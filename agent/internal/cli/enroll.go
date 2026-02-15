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

	"github.com/portshield/agent/internal/config"
)

func runEnroll(platformURL string, workspaceID int, token string) error {
	baseURL, err := url.JoinPath(platformURL, "/api/agents/register")
	if err != nil {
		return fmt.Errorf("invalid URL: %w", err)
	}

	hostname, _ := os.Hostname()
	if hostname == "" {
		hostname = "unknown"
	}

	body := map[string]interface{}{
		"workspace_id":    workspaceID,
		"token":           token,
		"hostname":        hostname,
		"os":              runtime.GOOS,
		"arch":            runtime.GOARCH,
		"agent_version":   "1.0.0",
		"primary_protocol": "http_api",
		"enabled_protocols": []string{"http_api"},
		"permissions":     []string{"system_metrics", "inventory", "network", "filesystem"},
	}

	jsonBody, _ := json.Marshal(body)
	req, err := http.NewRequest("POST", baseURL, bytes.NewReader(jsonBody))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")

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
			AgentID    string   `json:"agent_id"`
			AgentSecret string   `json:"agent_secret"`
		} `json:"data"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&result); err != nil {
		return fmt.Errorf("decode response: %w", err)
	}
	if !result.Success || result.Data.AgentID == "" || result.Data.AgentSecret == "" {
		return fmt.Errorf("invalid response: missing agent_id or agent_secret")
	}

	cfg := config.Config{
		PlatformURL: platformURL,
		WorkspaceID:  workspaceID,
		AgentID:     result.Data.AgentID,
		AgentSecret: result.Data.AgentSecret,
	}

	cfgPath, err := config.DefaultPath()
	if err != nil {
		return err
	}
	dir := filepath.Dir(cfgPath)
	if err := os.MkdirAll(dir, 0700); err != nil {
		return fmt.Errorf("create config dir: %w", err)
	}

	data, err := json.MarshalIndent(cfg, "", "  ")
	if err != nil {
		return err
	}
	if err := os.WriteFile(cfgPath, data, 0600); err != nil {
		return fmt.Errorf("write config: %w", err)
	}

	fmt.Printf("Enrolled successfully. Config saved to %s\n", cfgPath)
	fmt.Println("Run 'portshield-agent run' to start the agent.")
	return nil
}
