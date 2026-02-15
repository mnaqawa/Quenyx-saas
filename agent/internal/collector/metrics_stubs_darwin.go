package collector

import "runtime"

//go:build !darwin
func collectDarwinMetrics(m map[string]interface{}) {
	m["cpu"] = map[string]interface{}{"cores": runtime.NumCPU()}
}
