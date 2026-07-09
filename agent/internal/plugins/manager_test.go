package plugins_test

import (
	"testing"

	"github.com/quenyx/agent/internal/plugins"
)

func TestDangerousPluginsDisabledWithoutPermission(t *testing.T) {
	mgr := plugins.NewManager([]string{"system_metrics", "inventory", "filesystem"})
	auto := mgr.ByKey("automation_runner")
	if auto == nil || auto.Enabled() {
		t.Fatal("automation_runner must be disabled without automation permission")
	}
	comp := mgr.ByKey("compliance")
	if comp == nil || comp.Enabled() {
		t.Fatal("compliance must be disabled without compliance permission")
	}
}

func TestDangerousPluginsEnabledWithPermission(t *testing.T) {
	mgr := plugins.NewManager([]string{"system_metrics", "automation", "compliance"})
	if !mgr.ByKey("automation_runner").Enabled() {
		t.Fatal("automation_runner should enable when automation permission granted")
	}
	if !mgr.ByKey("compliance").Enabled() {
		t.Fatal("compliance should enable when compliance permission granted")
	}
}
