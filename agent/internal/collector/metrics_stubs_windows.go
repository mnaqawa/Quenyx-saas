package collector

import "runtime"

//go:build !windows
func collectWindowsMetrics(m map[string]interface{}) {
	m["cpu"] = map[string]interface{}{"cores": runtime.NumCPU()}
}
