package cli

import (
	"encoding/json"
	"fmt"
	"os"

	"github.com/quenyx/agent/internal/config"
	"github.com/quenyx/agent/internal/diagnostics"
	"github.com/quenyx/agent/internal/plugins"
	"github.com/quenyx/agent/internal/policy"
)

func runStatus(cfgPath string) error {
	cfg, err := config.Load(cfgPath)
	if err != nil {
		return err
	}
	mgr := plugins.NewManager(cfg.Permissions)

	fmt.Printf("Agent ID:        %s\n", cfg.AgentID)
	fmt.Printf("Workspace ID:    %d\n", cfg.WorkspaceID)
	fmt.Printf("Gateway URL:     %s\n", cfg.PlatformURL)
	fmt.Printf("Agent version:   %s\n", cfg.AgentVersion)
	fmt.Printf("Policy version:  %s\n", cfg.PolicyVersion)
	fmt.Printf("Platform version:%s\n", cfg.PlatformVersion)
	fmt.Printf("Lifecycle:       %s\n", cfg.LifecycleStatus)
	if cfg.Diagnostics != nil {
		fmt.Printf("Last heartbeat:  %s (%s, %.0fms)\n",
			cfg.Diagnostics.LastHeartbeatAt,
			cfg.Diagnostics.LastHeartbeatStatus,
			cfg.Diagnostics.LastHeartbeatLatencyMs,
		)
		policyStatus := cfg.Diagnostics.PolicyStatus
		if policyStatus == "" {
			policyStatus = policy.LocalPolicyStatus(cfg)
		}
		fmt.Printf("Policy status:   %s\n", policyStatus)
		if cfg.Diagnostics.LastError != "" {
			fmt.Printf("Last error:      %s\n", cfg.Diagnostics.LastError)
		}
	}
	if cfg.FailoverGateway != nil && cfg.FailoverGateway.EndpointURL != "" {
		fmt.Printf("Failover gateway:%s\n", cfg.FailoverGateway.EndpointURL)
	}
	fmt.Printf("Enabled plugins: ")
	for i, p := range mgr.Enabled() {
		if i > 0 {
			fmt.Print(", ")
		}
		fmt.Print(p.PluginKey)
	}
	fmt.Println()
	return nil
}

func runDiagnostics(cfgPath string) error {
	cfg, err := config.Load(cfgPath)
	if err != nil {
		return err
	}
	mgr := plugins.NewManager(cfg.Permissions)

	report := diagnostics.BuildSupportBundle(cfg, mgr, nil)

	enc := json.NewEncoder(os.Stdout)
	enc.SetIndent("", "  ")
	return enc.Encode(report)
}

func pluginKeys(descs []*plugins.Descriptor) []string {
	out := make([]string, 0, len(descs))
	for _, d := range descs {
		out = append(out, d.PluginKey)
	}
	return out
}
