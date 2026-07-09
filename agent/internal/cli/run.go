package cli

import (
	"encoding/json"
	"fmt"
	"log"
	"os"
	"os/signal"
	"path/filepath"
	"syscall"
	"time"

	"github.com/quenyx/agent/internal/collector"
	"github.com/quenyx/agent/internal/config"
	"github.com/quenyx/agent/internal/configsync"
	"github.com/quenyx/agent/internal/gateway"
	"github.com/quenyx/agent/internal/heartbeat"
	"github.com/quenyx/agent/internal/plugins"
	"github.com/quenyx/agent/internal/policy"
	"github.com/quenyx/agent/internal/queue"
	"github.com/quenyx/agent/internal/update"
	"github.com/quenyx/agent/internal/version"
)

type agentRuntime struct {
	cfgPath    string
	cfg        *config.Config
	plugins    *plugins.Manager
	gw         *gateway.Client
	queue      *queue.DiskQueue
	updater    *update.Manager
	bytesSent  uint64
	bytesRecv  uint64
	updateProg map[string]interface{}
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
		updater: update.NewManager(filepath.Join(filepath.Dir(cfgPath), "quenyx")),
	}
	queueDir := filepath.Join(filepath.Dir(cfgPath), "quenyx", "queue")
	q, err := queue.Open(queueDir, 10000, 256)
	if err != nil {
		log.Printf("[qpa] offline queue warning: %v", err)
	} else {
		rt.queue = q
	}

	hbSec := configsync.HeartbeatInterval(cfg, 300)
	metricsSec := configsync.TelemetryInterval(cfg, 60)
	inventorySec := configsync.InventoryInterval(cfg, 21600)

	heartbeatInterval := time.Duration(hbSec) * time.Second
	metricsInterval := time.Duration(metricsSec) * time.Second
	inventoryInterval := time.Duration(inventorySec) * time.Second

	log.Printf("[qpa] status=started workspace_id=%d agent_id=%s version=%s permissions=%v hb=%s metrics=%s",
		cfg.WorkspaceID, cfg.AgentID, version.Agent, cfg.Permissions, heartbeatInterval, metricsInterval)

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

	var queueStats map[string]interface{}
	if rt.queue != nil {
		queueStats = rt.queue.Stats().ToMap()
	}

	body := heartbeat.BuildPayload(rt.cfg, rt.plugins, privateIP, publicIP, rt.bytesSent, rt.bytesRecv, queueStats, rt.updateProg)

	if rt.queue != nil {
		events, _ := rt.queue.Drain(50)
		if len(events) > 0 {
			replay := make([]map[string]interface{}, 0, len(events))
			for _, ev := range events {
				replay = append(replay, map[string]interface{}{
					"event_type": ev.EventType,
					"dedup_key":  ev.DedupKey,
					"event_at":   ev.EventAt,
					"payload":    ev.Payload,
				})
			}
			body["offline_replay"] = replay
		}
	}

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

	if rt.queue != nil {
		if replay, ok := body["offline_replay"].([]map[string]interface{}); ok && len(replay) > 0 {
			keys := make([]string, 0, len(replay))
			for _, ev := range replay {
				if k, ok := ev["dedup_key"].(string); ok {
					keys = append(keys, k)
				}
			}
			_ = rt.queue.Ack(keys)
		}
	}

	rt.updateProg = nil
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

	if resp.Data.Configuration != nil {
		changed := configsync.Apply(rt.cfg, &configsync.RemoteSettings{
			Version:  resp.Data.Configuration.Version,
			Settings: resp.Data.Configuration.Settings,
		})
		if changed {
			log.Printf("[qpa] configuration synced to version %s", rt.cfg.ConfigVersion)
		}
	}

	if resp.Data.Update != nil && resp.Data.Update.MayProceed {
		prog := rt.updater.Evaluate(&update.Instruction{
			MayProceed:     resp.Data.Update.MayProceed,
			Approved:       resp.Data.Update.Approved,
			TargetVersion:  resp.Data.Update.TargetVersion,
			DownloadURL:    resp.Data.Update.DownloadURL,
			ChecksumSHA256: resp.Data.Update.ChecksumSHA256,
			Signature:      resp.Data.Update.Signature,
		})
		if prog != nil {
			rt.updateProg = map[string]interface{}{
				"status":   prog.Status,
				"progress": prog.Progress,
				"result":   prog.Result,
				"error":    prog.Error,
			}
			log.Printf("[qpa] update status=%s progress=%d", prog.Status, prog.Progress)
		}
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
		rt.enqueueOffline("telemetry", body)
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
		rt.enqueueOffline("inventory", body)
		log.Printf("[qpa] inventory send failed: %v (keys: %v)", err, keys)
		return
	}
	log.Printf("[qpa] inventory shared=%v status=accepted", keys)
}

func (rt *agentRuntime) enqueueOffline(eventType string, payload map[string]interface{}) {
	if rt.queue == nil {
		return
	}
	key := fmt.Sprintf("%s-%s", eventType, time.Now().UTC().Format(time.RFC3339Nano))
	_ = rt.queue.Enqueue(queue.Event{
		EventType: eventType,
		DedupKey:  key,
		EventAt:   time.Now().UTC().Format(time.RFC3339),
		Payload:   payload,
	})
}
