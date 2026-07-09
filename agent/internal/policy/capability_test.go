package policy_test

import (
	"testing"

	"github.com/quenyx/agent/internal/policy"
)

func TestCapabilityHashMatchesSortedJSON(t *testing.T) {
	caps := []string{"monitoring.telemetry", "asset.inventory", "monitoring.service_checks"}
	h1 := policy.CapabilityHash(caps)
	caps2 := []string{"asset.inventory", "monitoring.service_checks", "monitoring.telemetry"}
	h2 := policy.CapabilityHash(caps2)
	if h1 == "" {
		t.Fatal("expected non-empty hash")
	}
	if h1 != h2 {
		t.Fatalf("hash should be order-independent: %s vs %s", h1, h2)
	}
}
