package cli

import (
	"bytes"
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"net/url"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/quenyx/agent/internal/config"
	"github.com/quenyx/agent/internal/collector"
)

func runAgent(cfgPath string) error {
	cfg, err := config.Load(cfgPath)
	if err != nil {
		return fmt.Errorf("load config: %w", err)
	}

	baseURL := cfg.PlatformURL
	agentID := cfg.AgentID
	secret := cfg.AgentSecret

	log.Printf("[agent] status=started workspace_id=%d agent_id=%s primary_protocol=%s permissions=%v",
		cfg.WorkspaceID, agentID, cfg.PrimaryProtocol, cfg.Permissions)

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
	privateIP := getPrivateIP()
	publicIP := getPublicIP()
	body := map[string]interface{}{}
	if privateIP != "" {
		body["private_ip"] = privateIP
	}
	if publicIP != "" {
		body["public_ip"] = publicIP
	}
	jsonBody, _ := json.Marshal(body)
	if len(jsonBody) == 2 {
		jsonBody = []byte("{}")
	}
	u, _ := url.JoinPath(baseURL, "/api/agents/", agentID, "/heartbeat")
	req, _ := http.NewRequest("POST", u, bytes.NewReader(jsonBody))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Agent-Secret", secret)
	client := &http.Client{Timeout: 30 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		log.Printf("[agent] heartbeat failed: %v", err)
		return
	}
	resp.Body.Close()
	if resp.StatusCode == http.StatusOK {
		log.Printf("[agent] heartbeat status=ok")
	} else {
		log.Printf("[agent] heartbeat status=error http=%d", resp.StatusCode)
	}
}

func sendMetrics(baseURL, agentID, secret string) {
	payload := collector.CollectMetrics()
	keys := make([]string, 0, len(payload))
	for k := range payload {
		keys = append(keys, k)
	}
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
		log.Printf("[agent] metrics send failed: %v (shared: %v)", err, keys)
		return
	}
	resp.Body.Close()
	if resp.StatusCode == http.StatusOK {
		log.Printf("[agent] metrics shared=%v status=accepted", keys)
	} else {
		log.Printf("[agent] metrics shared=%v status=error http=%d", keys, resp.StatusCode)
	}
}

func sendInventory(baseURL, agentID, secret string) {
	payload := collector.CollectInventory()
	keys := make([]string, 0, len(payload))
	for k := range payload {
		keys = append(keys, k)
	}
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
		log.Printf("[agent] inventory send failed: %v (shared: %v)", err, keys)
		return
	}
	resp.Body.Close()
	if resp.StatusCode == http.StatusOK {
		log.Printf("[agent] inventory shared=%v status=accepted", keys)
	} else {
		log.Printf("[agent] inventory shared=%v status=error http=%d", keys, resp.StatusCode)
	}
}
