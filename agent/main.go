package main

import (
	"flag"
	"fmt"
	"os"

	"github.com/quenyx/agent/internal/cli"
)

var version = "1.0.1"

func main() {
	ver := flag.Bool("version", false, "Print version")
	flag.Parse()

	if *ver {
		fmt.Println("quenyx-agent", version)
		os.Exit(0)
	}

	if err := cli.Run(); err != nil {
		fmt.Fprintf(os.Stderr, "Error: %v\n", err)
		os.Exit(1)
	}
}
