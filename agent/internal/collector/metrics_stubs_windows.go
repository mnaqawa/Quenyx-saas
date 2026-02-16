//go:build !windows

package collector

import "runtime"

func collectWindowsMetrics(m map[string]interface{}) {
	m["cpu"] = map[string]interface{}{"cores": runtime.NumCPU()}
}
