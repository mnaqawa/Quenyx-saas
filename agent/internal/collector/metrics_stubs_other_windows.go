//go:build windows

package collector

import "runtime"

// Stubs for Darwin and Linux when building the Windows binary (cross-compile from Linux).
func collectDarwinMetrics(m map[string]interface{}) {
	m["cpu"] = map[string]interface{}{"cores": runtime.NumCPU()}
}

func collectLinuxMetrics(m map[string]interface{}) {
	m["cpu"] = map[string]interface{}{"cores": runtime.NumCPU()}
}
