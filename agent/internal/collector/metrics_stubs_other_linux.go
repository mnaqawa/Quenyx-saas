//go:build linux

package collector

import "runtime"

// Stubs for Windows and Darwin when building the Linux binary.
func collectWindowsMetrics(m map[string]interface{}) {
	m["cpu"] = map[string]interface{}{"cores": runtime.NumCPU()}
}

func collectDarwinMetrics(m map[string]interface{}) {
	m["cpu"] = map[string]interface{}{"cores": runtime.NumCPU()}
}
