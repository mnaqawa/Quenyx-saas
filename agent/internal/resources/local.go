package resources

import (
	"net"
	"os"
	"runtime"
	"strings"
)

const TypeLocalHost = "local_host"

// LocalHost builds the managed local_host resource payload (real data only).
func LocalHost(privateIP, publicIP string) map[string]interface{} {
	hostname, _ := os.Hostname()
	if hostname == "" {
		hostname = "unknown"
	}

	privateIPs := []string{}
	if privateIP != "" {
		privateIPs = append(privateIPs, privateIP)
	}

	meta := map[string]interface{}{
		"os":          runtime.GOOS,
		"arch":        runtime.GOARCH,
		"hostname":    hostname,
		"private_ips": privateIPs,
		"cpu_cores":   runtime.NumCPU(),
	}
	if publicIP != "" {
		meta["public_ip"] = publicIP
	}

	ifaces := CollectInterfaces()
	if len(ifaces) > 0 {
		meta["interfaces"] = ifaces
	}

	return map[string]interface{}{
		"resource_type":        TypeLocalHost,
		"display_name":         hostname,
		"is_monitoring_target": true,
		"lifecycle_status":     "active",
		"health_status":        "online",
		"metadata":             meta,
	}
}

// CollectInterfaces returns network interface facts from the host OS.
func CollectInterfaces() []map[string]interface{} {
	ifaces, err := net.Interfaces()
	if err != nil {
		return nil
	}
	out := make([]map[string]interface{}, 0, len(ifaces))
	for _, iface := range ifaces {
		if iface.Flags&net.FlagUp == 0 {
			continue
		}
		addrs := make([]string, 0)
		if ifaceAddrs, err := iface.Addrs(); err == nil {
			for _, a := range ifaceAddrs {
				ipnet, ok := a.(*net.IPNet)
				if !ok || ipnet.IP.To4() == nil {
					continue
				}
				addrs = append(addrs, ipnet.IP.String())
			}
		}
		out = append(out, map[string]interface{}{
			"name":  iface.Name,
			"mac":   iface.HardwareAddr.String(),
			"flags": iface.Flags.String(),
			"ips":   addrs,
		})
	}
	return out
}

// AllPrivateIPs returns all non-loopback IPv4 addresses.
func AllPrivateIPs() []string {
	ifaces, err := net.Interfaces()
	if err != nil {
		return nil
	}
	seen := map[string]bool{}
	var ips []string
	for _, iface := range ifaces {
		if iface.Flags&net.FlagUp == 0 || iface.Flags&net.FlagLoopback != 0 {
			continue
		}
		addrs, err := iface.Addrs()
		if err != nil {
			continue
		}
		for _, a := range addrs {
			ipnet, ok := a.(*net.IPNet)
			if !ok || ipnet.IP.IsLoopback() || ipnet.IP.To4() == nil {
				continue
			}
			ip := ipnet.IP.String()
			if strings.HasPrefix(ip, "169.254.") {
				continue
			}
			if !seen[ip] {
				seen[ip] = true
				ips = append(ips, ip)
			}
		}
	}
	return ips
}
