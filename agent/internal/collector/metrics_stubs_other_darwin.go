//go:build darwin

package collector

import "runtime"

// Stubs for Windows and Linux when building the Darwin binary.
func collectWindowsMetrics(m map[string]interface{}) {
	m["cpu"] = map[string]interface{}{"cores": runtime.NumCPU()}
}

func collectLinuxMetrics(m map[string]interface{}) {
	m["cpu"] = map[string]interface{}{"cores": runtime.NumCPU()}
}
