package cli

import (
	"flag"
	"fmt"
	"os"
)

func Run() error {
	enroll := flag.NewFlagSet("enroll", flag.ExitOnError)
	enrollURL := enroll.String("url", "", "Platform URL (e.g. https://quenyx.example.com)")
	enrollWorkspace := enroll.Int("workspace", 0, "Workspace ID")
	enrollToken := enroll.String("token", "", "Enrollment token from the portal")

	run := flag.NewFlagSet("run", flag.ExitOnError)
	runConfig := run.String("config", "", "Config file path (default: ~/.config/quenyx/agent.json)")

	configCmd := flag.NewFlagSet("config", flag.ExitOnError)
	configPath := configCmd.String("config", "", "Config file path to show")

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
	fmt.Println(`Quenyx Agent - Cross-network monitoring and asset inventory

Usage:
  quenyx-agent enroll --url=URL --workspace=ID --token=TOKEN
  quenyx-agent run [--config=PATH]
  quenyx-agent config [--config=PATH]
  quenyx-agent install [--user=USER]

Commands:
  enroll   Register with the platform using an enrollment token from the portal
  run      Run the agent (heartbeat, metrics, inventory)
  config   Show config file path and contents (permissions, protocol, port)
  install  Install as a system service (Linux systemd, Windows service, macOS launchd)
`)
}
