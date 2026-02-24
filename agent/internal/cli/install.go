package cli

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
)

func runInstall(user string) error {
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
		return installSystemd(exe, user)
	case "windows":
		return installWindowsService(exe)
	case "darwin":
		return installLaunchd(exe)
	default:
		return fmt.Errorf("install not supported on %s", runtime.GOOS)
	}
}

func installSystemd(exe, user string) error {
	unit := fmt.Sprintf(`[Unit]
Description=Quenyx Agent
After=network.target

[Service]
Type=simple
User=%s
ExecStart=%s run
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
`, user, exe)

	path := "/etc/systemd/system/portshield-agent.service"
	if os.Getuid() != 0 {
		return fmt.Errorf("install requires root. Run: sudo %s install", exe)
	}
	if err := os.WriteFile(path, []byte(unit), 0644); err != nil {
		return fmt.Errorf("write unit file: %w", err)
	}
	cmd := exec.Command("systemctl", "daemon-reload")
	cmd.Run()
	cmd = exec.Command("systemctl", "enable", "portshield-agent")
	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("enable service: %w (%s)", err, string(out))
	}
	fmt.Println("Installed. Start with: sudo systemctl start portshield-agent")
	return nil
}

func installWindowsService(exe string) error {
	// TODO: Use golang.org/x/sys/windows/svc or similar
	return fmt.Errorf("Windows service install not yet implemented. Run manually from this directory: .\\portshield-agent.exe run  or use the full path: %s run", exe)
}

func installLaunchd(exe string) error {
	plist := fmt.Sprintf(`<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key>
  <string>com.portshield.agent</string>
  <key>ProgramArguments</key>
  <array>
    <string>%s</string>
    <string>run</string>
  </array>
  <key>RunAtLoad</key>
  <true/>
  <key>KeepAlive</key>
  <true/>
</dict>
</plist>
`, exe)

	path := "/Library/LaunchDaemons/com.portshield.agent.plist"
	if os.Getuid() != 0 {
		return fmt.Errorf("install requires root. Run: sudo %s install", exe)
	}
	if err := os.WriteFile(path, []byte(plist), 0644); err != nil {
		return fmt.Errorf("write plist: %w", err)
	}
	fmt.Println("Installed. Load with: sudo launchctl load " + path)
	return nil
}
