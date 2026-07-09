package heartbeat_test

import (
	"encoding/json"
	"testing"

	"github.com/quenyx/agent/internal/config"
	"github.com/quenyx/agent/internal/heartbeat"
	"github.com/quenyx/agent/internal/plugins"
)

func TestBuildPayloadIncludesFleetFields(t *testing.T) {
	cfg := &config.Config{
		PlatformURL:     "https://gw.example:9444",
		WorkspaceID:     1,
		AgentID:         "agent-uuid",
		AgentSecret:     "secret",
		PolicyVersion:   "1.0.0",
		PlatformVersion: "1.0.0",
		LifecycleStatus: "online",
		Permissions:     []string{"system_metrics", "inventory", "filesystem"},
	}
	mgr := plugins.NewManager(cfg.Permissions)
	body := heartbeat.BuildPayload(cfg, mgr, "10.0.0.5", "203.0.113.1", 100, 200, nil, nil)

	required := []string{
		"agent_version", "platform_version", "policy_version", "capability_hash",
		"plugin_versions", "managed_resources", "plugins", "lifecycle_status",
	}
	for _, key := range required {
		if _, ok := body[key]; !ok {
			t.Fatalf("missing key %s in heartbeat payload", key)
		}
	}

	resources, ok := body["managed_resources"].([]map[string]interface{})
	if !ok || len(resources) != 1 {
		t.Fatalf("expected one managed resource, got %#v", body["managed_resources"])
	}
	if resources[0]["resource_type"] != "local_host" {
		t.Fatalf("expected local_host resource type, got %v", resources[0]["resource_type"])
	}

	pluginList, ok := body["plugins"].([]plugins.Meta)
	if !ok {
		// JSON round-trip type may differ; re-marshal
		b, _ := json.Marshal(body["plugins"])
		var metas []map[string]interface{}
		_ = json.Unmarshal(b, &metas)
		if len(metas) < 3 {
			t.Fatalf("expected at least 3 plugins reported, got %d", len(metas))
		}
	} else if len(pluginList) < 3 {
		t.Fatalf("expected at least 3 plugins, got %d", len(pluginList))
	}
}

func TestParseResponseLegacyEmpty(t *testing.T) {
	resp, err := heartbeat.ParseResponse(nil)
	if err != nil {
		t.Fatal(err)
	}
	if resp != nil {
		t.Fatal("expected nil for empty body")
	}
}
