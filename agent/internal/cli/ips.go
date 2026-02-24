package cli

import (
	"net"
	"net/http"
	"strings"
	"time"
)

// getPrivateIP returns a best-effort private (local) IPv4 address.
// Prefers a non-loopback, non-link-local address.
func getPrivateIP() string {
	ifaces, err := net.Interfaces()
	if err != nil {
		return ""
	}
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
			return ip
		}
	}
	return ""
}

// getPublicIP attempts to discover the public (outbound) IP via a simple HTTP request.
// Returns empty string if unavailable or on error.
func getPublicIP() string {
	client := &http.Client{Timeout: 5 * time.Second}
	resp, err := client.Get("https://api.ipify.org")
	if err != nil {
		return ""
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return ""
	}
	buf := make([]byte, 64)
	n, _ := resp.Body.Read(buf)
	s := strings.TrimSpace(string(buf[:n]))
	if s == "" || net.ParseIP(s) == nil {
		return ""
	}
	return s
}
