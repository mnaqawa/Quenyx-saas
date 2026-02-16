//go:build !darwin

package collector

import "runtime"

func collectDarwinMetrics(m map[string]interface{}) {
	m["cpu"] = map[string]interface{}{"cores": runtime.NumCPU()}
}
