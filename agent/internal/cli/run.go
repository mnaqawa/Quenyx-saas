package cli

import (
	"encoding/json"
	"fmt"
	"log"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/quenyx/agent/internal/collector"
	"github.com/quenyx/agent/internal/config"
	"github.com/quenyx/agent/internal/gateway"
	"github.com/quenyx/agent/internal/heartbeat"
	"github.com/quenyx/agent/internal/plugins"
	"github.com/quenyx/agent/internal/policy"
	"github.com/quenyx/agent/internal/version"
)

type agentRuntime struct {
	cfgPath    string
	cfg        *config.Config
	plugins    *plugins.Manager
	gw         *gateway.Client
	bytesSent  uint64
	bytesRecv  uint64
}

func runAgent(cfgPath string) error {
	cfg, err := config.Load(cfgPath)
	if err != nil {
		return fmt.Errorf("load config: %w", err)
	}
	if cfg.Hostname == "" {
		cfg.Hostname, _ = os.Hostname()
	}

	rt := &agentRuntime{
		cfgPath: cfgPath,
		cfg:     cfg,
		plugins: plugins.NewManager(cfg.Permissions),
		gw:      gateway.NewClient(cfg),
	}

	log.Printf("[qpa] status=started workspace_id=%d agent_id=%s version=%s permissions=%v",
		cfg.WorkspaceID, cfg.AgentID, version.Agent, cfg.Permissions)

	heartbeatInterval := 5 * time.Minute
	metricsInterval := 1 * time.Minute
	inventoryInterval := 6 * time.Hour

	tickerHeartbeat := time.NewTicker(heartbeatInterval)
	tickerMetrics := time.NewTicker(metricsInterval)
	tickerInventory := time.NewTicker(inventoryInterval)
	defer tickerHeartbeat.Stop()
	defer tickerMetrics.Stop()
	defer tickerInventory.Stop()

	rt.doHeartbeat()
	rt.doMetrics()
	rt.doInventory()

	sig := make(chan os.Signal, 1)
	signal.Notify(sig, syscall.SIGINT, syscall.SIGTERM)

	for {
		select {
		case <-sig:
			return nil
		case <-tickerHeartbeat.C:
			rt.doHeartbeat()
		case <-tickerMetrics.C:
			rt.doMetrics()
		case <-tickerInventory.C:
			rt.doInventory()
		}
	}
}

func (rt *agentRuntime) doHeartbeat() {
	privateIP := getPrivateIP()
	publicIP := getPublicIP()
	body := heartbeat.BuildPayload(rt.cfg, rt.plugins, privateIP, publicIP, rt.bytesSent, rt.bytesRecv)

	jsonBody, _ := json.Marshal(body)
	rt.bytesSent += uint64(len(jsonBody))

	res := rt.gw.PostJSON(rt.cfg.AgentID, rt.cfg.AgentSecret, "heartbeat", body)
	if res.Body != nil {
		rt.bytesRecv += uint64(len(res.Body))
	}

	if res.Err != nil {
		heartbeat.UpdateDiagnostics(rt.cfg, "error", res.Latency, res.Err.Error())
		log.Printf("[qpa] heartbeat failed: %v", res.Err)
		_ = config.Save(rt.cfg, rt.cfgPath)
		return
	}

	if res.StatusCode != 200 {
		errMsg := fmt.Sprintf("http %d", res.StatusCode)
		heartbeat.UpdateDiagnostics(rt.cfg, "error", res.Latency, errMsg)
		log.Printf("[qpa] heartbeat status=error %s", errMsg)
		_ = config.Save(rt.cfg, rt.cfgPath)
		return
	}

	heartbeat.UpdateDiagnostics(rt.cfg, "ok", res.Latency, "")
	rt.applyHeartbeatResponse(res.Body)
	log.Printf("[qpa] heartbeat status=ok latency=%s policy=%s",
		res.Latency.Round(time.Millisecond), rt.cfg.Diagnostics.PolicyStatus)
	_ = config.Save(rt.cfg, rt.cfgPath)
}

func (rt *agentRuntime) applyHeartbeatResponse(body []byte) {
	resp, err := heartbeat.ParseResponse(body)
	if err != nil {
		log.Printf("[qpa] heartbeat response parse warning: %v", err)
		rt.cfg.Diagnostics.PolicyStatus = policy.LocalPolicyStatus(rt.cfg)
		return
	}
	if resp == nil || !resp.Success {
		rt.cfg.Diagnostics.PolicyStatus = policy.LocalPolicyStatus(rt.cfg)
		return
	}

	if resp.Data.FailoverGateway != nil && resp.Data.FailoverGateway.EndpointURL != "" {
		rt.cfg.FailoverGateway = resp.Data.FailoverGateway
		rt.gw.FailoverURL = resp.Data.FailoverGateway.EndpointURL
		log.Printf("[qpa] failover gateway stored (not switching primary): %s", resp.Data.FailoverGateway.EndpointURL)
	}

	syncResult := policy.Apply(rt.cfg, rt.plugins, resp.Data.Policy)
	rt.cfg.Diagnostics.PolicyStatus = syncResult.PolicyStatus
	if syncResult.Error != "" {
		rt.cfg.Diagnostics.LastError = syncResult.Error
		rt.cfg.LifecycleStatus = "policy_sync_pending"
		log.Printf("[qpa] policy sync: %s", syncResult.Error)
	} else if syncResult.PolicyChanged {
		log.Printf("[qpa] policy updated to version %s", rt.cfg.PolicyVersion)
	}
}

func (rt *agentRuntime) doMetrics() {
	if rt.plugins.ByKey("monitoring") == nil || !rt.plugins.ByKey("monitoring").Enabled() {
		return
	}
	payload := collector.CollectMetrics()
	keys := make([]string, 0, len(payload))
	for k := range payload {
		keys = append(keys, k)
	}
	body := map[string]interface{}{
		"collected_at": time.Now().UTC().Format(time.RFC3339),
		"payload":      payload,
	}
	var err error
	res := rt.gw.PostJSON(rt.cfg.AgentID, rt.cfg.AgentSecret, "telemetry", body)
	if res.Err != nil {
		err = res.Err
	} else if res.StatusCode != 200 {
		err = fmt.Errorf("http %d", res.StatusCode)
	}
	rt.plugins.RecordPluginRun("monitoring", err)
	if err != nil {
		log.Printf("[qpa] telemetry send failed: %v (keys: %v)", err, keys)
		return
	}
	log.Printf("[qpa] telemetry shared=%v status=accepted", keys)
}

func (rt *agentRuntime) doInventory() {
	if rt.plugins.ByKey("inventory") == nil || !rt.plugins.ByKey("inventory").Enabled() {
		return
	}
	payload := collector.CollectInventory()
	keys := make([]string, 0, len(payload))
	for k := range payload {
		keys = append(keys, k)
	}
	body := map[string]interface{}{
		"collected_at": time.Now().UTC().Format(time.RFC3339),
		"payload":      payload,
	}
	var err error
	res := rt.gw.PostJSON(rt.cfg.AgentID, rt.cfg.AgentSecret, "inventory", body)
	if res.Err != nil {
		err = res.Err
	} else if res.StatusCode != 200 {
		err = fmt.Errorf("http %d", res.StatusCode)
	}
	rt.plugins.RecordPluginRun("inventory", err)
	if err != nil {
		log.Printf("[qpa] inventory send failed: %v (keys: %v)", err, keys)
		return
	}
	log.Printf("[qpa] inventory shared=%v status=accepted", keys)
}
