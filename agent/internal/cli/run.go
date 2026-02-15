package cli

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/portshield/agent/internal/config"
	"github.com/portshield/agent/internal/collector"
)

func runAgent(cfgPath string) error {
	cfg, err := config.Load(cfgPath)
	if err != nil {
		return fmt.Errorf("load config: %w", err)
	}

	baseURL := cfg.PlatformURL
	agentID := cfg.AgentID
	secret := cfg.AgentSecret

	// Heartbeat every 5 minutes
	heartbeatInterval := 5 * time.Minute
	// Metrics every 1 minute
	metricsInterval := 1 * time.Minute
	// Inventory every 6 hours
	inventoryInterval := 6 * time.Hour

	tickerHeartbeat := time.NewTicker(heartbeatInterval)
	tickerMetrics := time.NewTicker(metricsInterval)
	tickerInventory := time.NewTicker(inventoryInterval)
	defer tickerHeartbeat.Stop()
	defer tickerMetrics.Stop()
	defer tickerInventory.Stop()

	// Run once immediately
	sendHeartbeat(baseURL, agentID, secret)
	sendMetrics(baseURL, agentID, secret)
	sendInventory(baseURL, agentID, secret)

	sig := make(chan os.Signal, 1)
	signal.Notify(sig, syscall.SIGINT, syscall.SIGTERM)

	for {
		select {
		case <-sig:
			return nil
		case <-tickerHeartbeat.C:
			sendHeartbeat(baseURL, agentID, secret)
		case <-tickerMetrics.C:
			sendMetrics(baseURL, agentID, secret)
		case <-tickerInventory.C:
			sendInventory(baseURL, agentID, secret)
		}
	}
}

func sendHeartbeat(baseURL, agentID, secret string) {
	u, _ := url.JoinPath(baseURL, "/api/agents/", agentID, "/heartbeat")
	req, _ := http.NewRequest("POST", u, bytes.NewReader([]byte("{}")))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Agent-Secret", secret)
	client := &http.Client{Timeout: 30 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return
	}
	resp.Body.Close()
}

func sendMetrics(baseURL, agentID, secret string) {
	payload := collector.CollectMetrics()
	body := map[string]interface{}{
		"collected_at": time.Now().UTC().Format(time.RFC3339),
		"payload":      payload,
	}
	jsonBody, _ := json.Marshal(body)
	u, _ := url.JoinPath(baseURL, "/api/agents/", agentID, "/metrics")
	req, _ := http.NewRequest("POST", u, bytes.NewReader(jsonBody))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Agent-Secret", secret)
	client := &http.Client{Timeout: 30 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return
	}
	resp.Body.Close()
}

func sendInventory(baseURL, agentID, secret string) {
	payload := collector.CollectInventory()
	body := map[string]interface{}{
		"collected_at": time.Now().UTC().Format(time.RFC3339),
		"payload":      payload,
	}
	jsonBody, _ := json.Marshal(body)
	u, _ := url.JoinPath(baseURL, "/api/agents/", agentID, "/inventory")
	req, _ := http.NewRequest("POST", u, bytes.NewReader(jsonBody))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Agent-Secret", secret)
	client := &http.Client{Timeout: 60 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return
	}
	resp.Body.Close()
}
