package cli

import (
	"fmt"
	"os"
	"os/exec"
	"os/user"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"

	"github.com/quenyx/agent/internal/config"
)

const (
	linuxBinaryPath = "/opt/quenyx-agent"
	linuxUnitPath   = "/etc/systemd/system/quenyx-agent.service"
	linuxHomeDir    = "/var/lib/quenyx"
)

func runInstall(userName string) error {
	exe, err := os.Executable()
	if err != nil {
		return fmt.Errorf("get executable path: %w", err)
	}
	exe, err = filepath.Abs(exe)
	if err != nil {
		return err
	}

	switch runtime.GOOS {
	case "linux":
		return installSystemd(exe, userName)
	case "windows":
		return installWindowsService(exe)
	case "darwin":
		return installLaunchd(exe)
	default:
		return fmt.Errorf("install not supported on %s", runtime.GOOS)
	}
}

func installSystemd(exe, userName string) error {
	if os.Getuid() != 0 {
		return fmt.Errorf("install requires root. Run: sudo %s install", exe)
	}
	if strings.TrimSpace(userName) == "" {
		userName = "quenyx"
	}

	if err := ensureLinuxServiceUser(userName); err != nil {
		return err
	}

	// Install a stable binary path so restarts survive cwd changes.
	if err := installLinuxBinary(exe); err != nil {
		return err
	}

	cfgPath, err := migrateConfigForService(userName)
	if err != nil {
		return err
	}

	unit := fmt.Sprintf(`[Unit]
Description=Quenyx Agent
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=%s
Group=%s
Environment=HOME=%s
WorkingDirectory=%s
ExecStart=%s run --config=%s
Restart=always
RestartSec=10
KillMode=process

[Install]
WantedBy=multi-user.target
`, userName, userName, linuxHomeDir, linuxHomeDir, linuxBinaryPath, cfgPath)

	if err := os.WriteFile(linuxUnitPath, []byte(unit), 0644); err != nil {
		return fmt.Errorf("write unit file: %w", err)
	}

	if out, err := exec.Command("systemctl", "daemon-reload").CombinedOutput(); err != nil {
		return fmt.Errorf("daemon-reload: %w (%s)", err, string(out))
	}
	if out, err := exec.Command("systemctl", "enable", "quenyx-agent").CombinedOutput(); err != nil {
		return fmt.Errorf("enable service: %w (%s)", err, string(out))
	}
	_ = exec.Command("systemctl", "reset-failed", "quenyx-agent").Run()
	if out, err := exec.Command("systemctl", "restart", "quenyx-agent").CombinedOutput(); err != nil {
		return fmt.Errorf("start service: %w (%s)\nFix: ensure config exists at %s and user %s can read it", err, string(out), cfgPath, userName)
	}

	fmt.Printf("Installed and started quenyx-agent.service as user %s\n", userName)
	fmt.Printf("Config: %s\n", cfgPath)
	fmt.Println("Status: sudo systemctl status quenyx-agent")
	return nil
}

func ensureLinuxServiceUser(userName string) error {
	if _, err := user.Lookup(userName); err == nil {
		return nil
	}

	// Create a locked system account (systemd 217/USER happens when User= is missing).
	cmd := exec.Command("useradd", "--system", "--home-dir", linuxHomeDir, "--create-home", "--shell", "/usr/sbin/nologin", userName)
	if out, err := cmd.CombinedOutput(); err != nil {
		// Some distros use /sbin/nologin
		cmd = exec.Command("useradd", "--system", "--home-dir", linuxHomeDir, "--create-home", "--shell", "/sbin/nologin", userName)
		if out2, err2 := cmd.CombinedOutput(); err2 != nil {
			return fmt.Errorf("create user %s: %w (%s; %s)", userName, err2, string(out), string(out2))
		}
	}
	fmt.Printf("Created system user %s (home %s)\n", userName, linuxHomeDir)
	return nil
}

func installLinuxBinary(src string) error {
	data, err := os.ReadFile(src)
	if err != nil {
		return fmt.Errorf("read binary: %w", err)
	}
	tmp := linuxBinaryPath + ".new"
	if err := os.WriteFile(tmp, data, 0755); err != nil {
		return fmt.Errorf("write %s: %w", tmp, err)
	}
	if err := os.Rename(tmp, linuxBinaryPath); err != nil {
		return fmt.Errorf("install binary to %s: %w", linuxBinaryPath, err)
	}
	return nil
}

func migrateConfigForService(userName string) (string, error) {
	systemPath := config.SystemPath()
	if err := os.MkdirAll(filepath.Dir(systemPath), 0755); err != nil {
		return "", fmt.Errorf("create config dir: %w", err)
	}
	if err := os.MkdirAll(linuxHomeDir, 0755); err != nil {
		return "", fmt.Errorf("create home dir: %w", err)
	}

	if _, err := os.Stat(systemPath); err == nil {
		_ = chownPath(systemPath, userName)
		_ = chownPath(filepath.Dir(systemPath), userName)
		_ = chownPath(linuxHomeDir, userName)
		return systemPath, nil
	}

	// Copy from common enrollment locations (root / invoking user / home trees).
	candidates := []string{}
	if p, err := config.UserPath(); err == nil {
		candidates = append(candidates, p)
	}
	for _, home := range []string{"/root", "/home/ec2-user", "/home/ubuntu", "/var/lib/quenyx"} {
		candidates = append(candidates, filepath.Join(home, ".config", "quenyx", "agent.json"))
	}

	var src string
	for _, c := range candidates {
		if c == "" {
			continue
		}
		if st, err := os.Stat(c); err == nil && !st.IsDir() {
			src = c
			break
		}
	}
	if src == "" {
		return "", fmt.Errorf("no agent config found. Enroll first, then re-run install:\n  sudo %s enroll --url=... --workspace=... --token=...\n  sudo %s install", linuxBinaryPath, linuxBinaryPath)
	}

	data, err := os.ReadFile(src)
	if err != nil {
		return "", fmt.Errorf("read config %s: %w", src, err)
	}
	if err := os.WriteFile(systemPath, data, 0600); err != nil {
		return "", fmt.Errorf("write %s: %w", systemPath, err)
	}
	_ = chownPath(systemPath, userName)
	_ = chownPath(filepath.Dir(systemPath), userName)
	_ = chownPath(linuxHomeDir, userName)
	fmt.Printf("Migrated config %s -> %s\n", src, systemPath)
	return systemPath, nil
}

func chownPath(path, userName string) error {
	u, err := user.Lookup(userName)
	if err != nil {
		return err
	}
	uid, err := strconv.Atoi(u.Uid)
	if err != nil {
		return err
	}
	gid, err := strconv.Atoi(u.Gid)
	if err != nil {
		return err
	}
	return os.Chown(path, uid, gid)
}

func installWindowsService(exe string) error {
	return fmt.Errorf("Windows service install not yet implemented. Run manually: %s run", exe)
}

func installLaunchd(exe string) error {
	plist := fmt.Sprintf(`<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key>
  <string>com.quenyx.agent</string>
  <key>ProgramArguments</key>
  <array>
    <string>%s</string>
    <string>run</string>
    <string>--config=%s</string>
  </array>
  <key>RunAtLoad</key>
  <true/>
  <key>KeepAlive</key>
  <true/>
</dict>
</plist>
`, exe, config.SystemPath())

	path := "/Library/LaunchDaemons/com.quenyx.agent.plist"
	if os.Getuid() != 0 {
		return fmt.Errorf("install requires root. Run: sudo %s install", exe)
	}
	if err := os.MkdirAll(filepath.Dir(config.SystemPath()), 0755); err != nil {
		return err
	}
	// Prefer migrating existing user config when present.
	if _, err := os.Stat(config.SystemPath()); err != nil {
		if up, err2 := config.UserPath(); err2 == nil {
			if data, err3 := os.ReadFile(up); err3 == nil {
				_ = os.WriteFile(config.SystemPath(), data, 0600)
			}
		}
	}
	if err := os.WriteFile(path, []byte(plist), 0644); err != nil {
		return fmt.Errorf("write plist: %w", err)
	}
	fmt.Println("Installed. Load with: sudo launchctl load " + path)
	return nil
}
