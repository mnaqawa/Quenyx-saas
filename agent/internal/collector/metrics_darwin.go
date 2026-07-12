//go:build darwin

package collector

import (
	"os/exec"
	"runtime"
	"strconv"
	"strings"
)

func collectDarwinMetrics(m map[string]interface{}) {
	// sysctl for load
	if out, err := exec.Command("sysctl", "-n", "vm.loadavg").Output(); err == nil {
		// vm.loadavg: { 1.23 4.56 7.89 }
		s := strings.TrimSpace(string(out))
		s = strings.TrimPrefix(s, "{ ")
		s = strings.TrimSuffix(s, " }")
		parts := strings.Fields(s)
		if len(parts) >= 3 {
			m["load"] = map[string]interface{}{
				"load1":  parseFloatDarwin(parts[0]),
				"load5":  parseFloatDarwin(parts[1]),
				"load15": parseFloatDarwin(parts[2]),
			}
		}
	}

	// vm_stat for memory
	if out, err := exec.Command("vm_stat").Output(); err == nil {
		lines := strings.Split(string(out), "\n")
		var pageSize uint64 = 4096
		var free, active, inactive, wired uint64
		for _, line := range lines {
			parts := strings.SplitN(line, ":", 2)
			if len(parts) != 2 {
				continue
			}
			val := parseUint64Darwin(strings.TrimSpace(strings.TrimSuffix(strings.TrimSpace(parts[1]), ".")))
			switch strings.TrimSpace(parts[0]) {
			case "Pages free":
				free = val
			case "Pages active":
				active = val
			case "Pages inactive":
				inactive = val
			case "Pages wired":
				wired = val
			}
		}
		total := (free + active + inactive + wired) * pageSize
		used := (active + inactive + wired) * pageSize
		if total > 0 {
			m["memory"] = map[string]interface{}{
				"total":     total,
				"available": free * pageSize,
				"used":      used,
				"used_pct":  float64(used) / float64(total) * 100,
			}
		}
	}

	m["cpu"] = map[string]interface{}{"cores": runtime.NumCPU()}

	// Root filesystem via df (portable on macOS).
	if out, err := exec.Command("df", "-k", "/").Output(); err == nil {
		lines := strings.Split(strings.TrimSpace(string(out)), "\n")
		if len(lines) >= 2 {
			fields := strings.Fields(lines[1])
			if len(fields) >= 4 {
				totalKB := parseUint64Darwin(fields[1])
				usedKB := parseUint64Darwin(fields[2])
				availKB := parseUint64Darwin(fields[3])
				if totalKB > 0 {
					total := totalKB * 1024
					used := usedKB * 1024
					free := availKB * 1024
					m["disk"] = map[string]interface{}{
						"/": map[string]interface{}{
							"total":    total,
							"used":     used,
							"free":     free,
							"used_pct": float64(used) / float64(total) * 100,
							"free_pct": float64(free) / float64(total) * 100,
						},
					}
				}
			}
		}
	}
}

func parseFloatDarwin(s string) float64 {
	f, _ := strconv.ParseFloat(s, 64)
	return f
}

func parseUint64Darwin(s string) uint64 {
	u, _ := strconv.ParseUint(s, 10, 64)
	return u
}
