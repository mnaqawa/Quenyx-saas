package cli

import (
	"flag"
	"fmt"
	"os"
)

func Run() error {
	enroll := flag.NewFlagSet("enroll", flag.ExitOnError)
	enrollURL := enroll.String("url", "", "Platform URL (e.g. https://cloud.quenyx.com:9444)")
	enrollWorkspace := enroll.Int("workspace", 0, "Workspace ID")
	enrollToken := enroll.String("token", "", "Enrollment token from the portal")

	run := flag.NewFlagSet("run", flag.ExitOnError)
	runConfig := run.String("config", "", "Config file path (default: ~/.config/quenyx/agent.json)")

	configCmd := flag.NewFlagSet("config", flag.ExitOnError)
	configPath := configCmd.String("config", "", "Config file path to show")

	statusCmd := flag.NewFlagSet("status", flag.ExitOnError)
	statusConfig := statusCmd.String("config", "", "Config file path")

	diagnosticsCmd := flag.NewFlagSet("diagnostics", flag.ExitOnError)
	diagnosticsConfig := diagnosticsCmd.String("config", "", "Config file path")

	install := flag.NewFlagSet("install", flag.ExitOnError)
	installUser := install.String("user", "quenyx", "User to run the agent (Linux systemd)")

	if len(os.Args) < 2 {
		printUsage()
		return nil
	}

	switch os.Args[1] {
	case "config":
		configCmd.Parse(os.Args[2:])
		return runConfigShow(*configPath)
	case "status":
		statusCmd.Parse(os.Args[2:])
		return runStatus(*statusConfig)
	case "diagnostics":
		diagnosticsCmd.Parse(os.Args[2:])
		return runDiagnostics(*diagnosticsConfig)
	case "enroll":
		enroll.Parse(os.Args[2:])
		if *enrollURL == "" || *enrollWorkspace == 0 || *enrollToken == "" {
			fmt.Fprintln(os.Stderr, "enroll requires: --url, --workspace, --token")
			enroll.Usage()
			os.Exit(1)
		}
		return runEnroll(*enrollURL, *enrollWorkspace, *enrollToken)
	case "run":
		run.Parse(os.Args[2:])
		return runAgent(*runConfig)
	case "install":
		install.Parse(os.Args[2:])
		return runInstall(*installUser)
	default:
		printUsage()
		return nil
	}
}

func printUsage() {
	fmt.Println(`Quenyx Platform Agent (QPA) — outbound HTTPS to Quenyx Agent Gateway

Usage:
  quenyx-agent enroll --url=URL --workspace=ID --token=TOKEN
  quenyx-agent run [--config=PATH]
  quenyx-agent status [--config=PATH]
  quenyx-agent diagnostics [--config=PATH]
  quenyx-agent config [--config=PATH]
  quenyx-agent install [--user=USER]

Commands:
  enroll       Register with the platform using an enrollment token
  run          Run the agent (heartbeat, metrics, inventory)
  status       Show agent connectivity and policy summary
  diagnostics  JSON diagnostics (gateway, plugins, heartbeat, policy)
  config       Show config file path and contents
  install      Install as a system service (Linux systemd, Windows service, macOS launchd)
`)
}
