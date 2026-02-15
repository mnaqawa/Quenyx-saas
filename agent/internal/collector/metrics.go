package collector

import (
	"runtime"
)

// CollectMetrics returns system metrics (CPU, memory, disk, load).
// Cross-platform: uses runtime and OS-specific collectors where available.
func CollectMetrics() map[string]interface{} {
	m := map[string]interface{}{
		"cpu": map[string]interface{}{
			"cores": runtime.NumCPU(),
		},
		"memory": map[string]interface{}{},
		"disk":   map[string]interface{}{},
		"load":   map[string]interface{}{},
	}

	// Platform-specific collection
	switch runtime.GOOS {
	case "linux":
		collectLinuxMetrics(m)
	case "windows":
		collectWindowsMetrics(m)
	case "darwin":
		collectDarwinMetrics(m)
	default:
		// Minimal fallback
		m["cpu"] = map[string]interface{}{"cores": runtime.NumCPU()}
	}

	return m
}
