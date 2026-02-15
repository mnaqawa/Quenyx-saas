//go:build windows

package collector

import "runtime"

func collectWindowsMetrics(m map[string]interface{}) {
	// TODO: Use syscall or golang.org/x/sys/windows for WMI/PDH
	// For now, minimal placeholder
	m["cpu"] = map[string]interface{}{"cores": runtime.NumCPU()}
	m["memory"] = map[string]interface{}{}
	m["load"] = map[string]interface{}{}
}
