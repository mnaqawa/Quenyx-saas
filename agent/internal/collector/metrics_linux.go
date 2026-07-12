//go:build linux

package collector

import (
	"os"
	"os/exec"
	"runtime"
	"strconv"
	"strings"
	"syscall"
)

func collectLinuxMetrics(m map[string]interface{}) {
	// /proc/loadavg: load1 load5 load15 ...
	if data, err := os.ReadFile("/proc/loadavg"); err == nil {
		parts := strings.Fields(string(data))
		if len(parts) >= 3 {
			m["load"] = map[string]interface{}{
				"load1":  parseFloat(parts[0]),
				"load5":  parseFloat(parts[1]),
				"load15": parseFloat(parts[2]),
			}
		}
	}

	// /proc/meminfo
	if data, err := os.ReadFile("/proc/meminfo"); err == nil {
		lines := strings.Split(string(data), "\n")
		var memTotal, memAvailable uint64
		for _, line := range lines {
			fields := strings.Fields(line)
			if len(fields) < 2 {
				continue
			}
			val := parseUint64(fields[1]) * 1024
			switch fields[0] {
			case "MemTotal:":
				memTotal = val
			case "MemAvailable:":
				memAvailable = val
			}
		}
		if memTotal > 0 {
			m["memory"] = map[string]interface{}{
				"total":     memTotal,
				"available": memAvailable,
				"used":      memTotal - memAvailable,
				"used_pct":  float64(memTotal-memAvailable) / float64(memTotal) * 100,
			}
		}
	}

	// /proc/stat for CPU (simplified)
	if data, err := os.ReadFile("/proc/stat"); err == nil {
		lines := strings.Split(string(data), "\n")
		for _, line := range lines {
			if strings.HasPrefix(line, "cpu ") {
				fields := strings.Fields(line)[1:]
				if len(fields) >= 4 {
					user := parseUint64(fields[0])
					nice := parseUint64(fields[1])
					sys := parseUint64(fields[2])
					idle := parseUint64(fields[3])
					total := user + nice + sys + idle
					if total > 0 {
						m["cpu"] = map[string]interface{}{
							"cores":    runtime.NumCPU(),
							"user":     user,
							"system":   sys,
							"idle":     idle,
							"total":    total,
							"used_pct": float64(total-idle) / float64(total) * 100,
						}
					}
				}
				break
			}
		}
	}

	if disk := collectLinuxDisk(); disk != nil {
		m["disk"] = disk
	}
}

func collectLinuxDisk() map[string]interface{} {
	var st syscall.Statfs_t
	if err := syscall.Statfs("/", &st); err == nil && st.Blocks > 0 {
		bsize := uint64(st.Bsize)
		if bsize == 0 {
			bsize = 4096
		}
		total := st.Blocks * bsize
		free := st.Bavail * bsize
		used := total - (st.Bfree * bsize)
		if total == 0 {
			return nil
		}
		usedPct := float64(used) / float64(total) * 100
		freePct := float64(free) / float64(total) * 100
		return map[string]interface{}{
			"/": map[string]interface{}{
				"total":    total,
				"used":     used,
				"free":     free,
				"used_pct": usedPct,
				"free_pct": freePct,
			},
		}
	}

	// Fallback when statfs is unavailable (rare containers / restricted mounts).
	out, err := exec.Command("df", "-Pk", "/").Output()
	if err != nil {
		return nil
	}
	lines := strings.Split(strings.TrimSpace(string(out)), "\n")
	if len(lines) < 2 {
		return nil
	}
	fields := strings.Fields(lines[1])
	// Filesystem 1024-blocks Used Available Capacity Mounted on
	if len(fields) < 6 {
		return nil
	}
	totalKB := parseUint64(fields[1])
	usedKB := parseUint64(fields[2])
	availKB := parseUint64(fields[3])
	if totalKB == 0 {
		return nil
	}
	total := totalKB * 1024
	used := usedKB * 1024
	free := availKB * 1024
	usedPct := float64(used) / float64(total) * 100
	freePct := float64(free) / float64(total) * 100
	return map[string]interface{}{
		"/": map[string]interface{}{
			"total":    total,
			"used":     used,
			"free":     free,
			"used_pct": usedPct,
			"free_pct": freePct,
		},
	}
}

func parseFloat(s string) float64 {
	f, _ := strconv.ParseFloat(s, 64)
	return f
}

func parseUint64(s string) uint64 {
	u, _ := strconv.ParseUint(s, 10, 64)
	return u
}
