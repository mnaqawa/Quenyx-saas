package collector

import (
	"os"
	"runtime"

	"github.com/quenyx/agent/internal/version"
)

// CollectInventory returns hardware and OS inventory.
func CollectInventory() map[string]interface{} {
	hostname, _ := os.Hostname()
	if hostname == "" {
		hostname = "unknown"
	}

	return map[string]interface{}{
		"hostname": hostname,
		"os":       runtime.GOOS,
		"arch":     runtime.GOARCH,
		"cpu_cores": runtime.NumCPU(),
		"agent_version": version.Agent,
	}
}
