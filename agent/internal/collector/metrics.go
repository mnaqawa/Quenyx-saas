package collector

import (
	"runtime"
)

// CollectMetrics returns system metrics (CPU, memory, disk, load).
// Only sections that were successfully collected are included (no empty placeholders).
func CollectMetrics() map[string]interface{} {
	m := map[string]interface{}{}

	switch runtime.GOOS {
	case "linux":
		collectLinuxMetrics(m)
	case "windows":
		collectWindowsMetrics(m)
	case "darwin":
		collectDarwinMetrics(m)
	default:
		m["cpu"] = map[string]interface{}{"cores": runtime.NumCPU()}
	}

	// Always report core count even if OS-specific CPU sampling failed.
	if _, ok := m["cpu"]; !ok {
		m["cpu"] = map[string]interface{}{"cores": runtime.NumCPU()}
	}

	return m
}
