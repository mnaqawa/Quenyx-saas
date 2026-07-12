//go:build windows

package collector

import "runtime"

func collectWindowsMetrics(m map[string]interface{}) {
	// Minimal placeholder until WMI/PDH collectors are added.
	// Do not emit empty memory/disk/load maps — those become UNKNOWN in QynSight.
	m["cpu"] = map[string]interface{}{"cores": runtime.NumCPU()}
}
