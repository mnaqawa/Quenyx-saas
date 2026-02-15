package collector

import "runtime"

// Stub for Linux when not building for Linux
//go:build !linux
func collectLinuxMetrics(m map[string]interface{}) {
	m["cpu"] = map[string]interface{}{"cores": runtime.NumCPU()}
}
