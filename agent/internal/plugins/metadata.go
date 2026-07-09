package plugins

import (
	"time"

	"github.com/quenyx/agent/internal/version"
)

// Meta is plugin metadata reported on heartbeat.
type Meta struct {
	PluginKey            string   `json:"plugin_key"`
	Name                 string   `json:"name"`
	Version              string   `json:"version"`
	Vendor               string   `json:"vendor"`
	Description          string   `json:"description,omitempty"`
	Status               string   `json:"status"`
	HealthStatus         string   `json:"health_status"`
	LastExecutionAt      string   `json:"last_execution_at,omitempty"`
	ErrorCount           int      `json:"error_count"`
	RequiredPermissions  []string `json:"required_permissions"`
	Dependencies         []string `json:"dependencies"`
	ConfigurationVersion string   `json:"configuration_version"`
}

// Descriptor is a registered plugin with runtime state.
type Descriptor struct {
	PluginKey            string
	Name                 string
	Version              string
	Vendor               string
	Description          string
	Capabilities         []string
	RequiredPermissions  []string
	Dependencies         []string
	ConfigurationVersion string
	Dangerous            bool
	// DefaultEnabled when no explicit permission gate applies.
	DefaultEnabled bool
	// PermissionGate — plugin only runs when one of these permissions is granted.
	PermissionGate []string
	enabled        bool
	health         string
	lastExecution  time.Time
	errorCount     int
}

func (d *Descriptor) IsGranted(permissions map[string]bool) bool {
	if d.Dangerous {
		for _, p := range d.PermissionGate {
			if permissions[p] {
				return true
			}
		}
		return false
	}
	if len(d.PermissionGate) == 0 {
		return d.DefaultEnabled
	}
	for _, p := range d.PermissionGate {
		if permissions[p] {
			return true
		}
	}
	return false
}

func (d *Descriptor) SetEnabled(on bool) {
	d.enabled = on
	if on {
		if d.health == "" || d.health == "disabled" {
			d.health = "unknown"
		}
	} else {
		d.health = "disabled"
	}
}

func (d *Descriptor) Enabled() bool { return d.enabled }

func (d *Descriptor) RecordExecution(err error) {
	d.lastExecution = time.Now().UTC()
	if err != nil {
		d.errorCount++
		d.health = "error"
	} else if d.enabled {
		d.health = "healthy"
	}
}

func (d *Descriptor) ToMeta() Meta {
	status := "disabled"
	if d.enabled {
		status = "active"
	}
	health := d.health
	if health == "" {
		health = "unknown"
	}
	lastExec := ""
	if !d.lastExecution.IsZero() {
		lastExec = d.lastExecution.Format(time.RFC3339)
	}
	ver := d.Version
	if ver == "" {
		ver = version.Agent
	}
	return Meta{
		PluginKey:            d.PluginKey,
		Name:                 d.Name,
		Version:              ver,
		Vendor:               d.Vendor,
		Description:          d.Description,
		Status:               status,
		HealthStatus:         health,
		LastExecutionAt:      lastExec,
		ErrorCount:           d.errorCount,
		RequiredPermissions:  d.RequiredPermissions,
		Dependencies:         d.Dependencies,
		ConfigurationVersion: d.ConfigurationVersion,
	}
}

// DefaultRegistry returns built-in plugins for Sprint 28.
func DefaultRegistry() []*Descriptor {
	return []*Descriptor{
		{
			PluginKey:            "monitoring",
			Name:                 "Monitoring",
			Version:              version.Agent,
			Vendor:               "Quenyx",
			Description:          "CPU, memory, disk, load telemetry",
			Capabilities:         []string{"monitoring.telemetry", "monitoring.service_checks"},
			RequiredPermissions:  []string{"system_metrics", "filesystem"},
			ConfigurationVersion: "1",
			DefaultEnabled:       true,
			PermissionGate:       []string{"system_metrics", "filesystem"},
			health:               "unknown",
		},
		{
			PluginKey:            "inventory",
			Name:                 "Inventory",
			Version:              version.Agent,
			Vendor:               "Quenyx",
			Description:          "Hardware and software inventory for QynAsset",
			Capabilities:         []string{"asset.inventory", "asset.hardware", "asset.software"},
			RequiredPermissions:  []string{"inventory"},
			ConfigurationVersion: "1",
			DefaultEnabled:       true,
			PermissionGate:       []string{"inventory"},
			health:               "unknown",
		},
		{
			PluginKey:            "network",
			Name:                 "Network",
			Version:              version.Agent,
			Vendor:               "Quenyx",
			Description:          "Network interface discovery",
			Capabilities:         []string{"asset.network"},
			RequiredPermissions:  []string{"network"},
			ConfigurationVersion: "1",
			DefaultEnabled:       false,
			PermissionGate:       []string{"network"},
			health:               "disabled",
		},
		{
			PluginKey:            "automation_runner",
			Name:                 "Automation Runner",
			Version:              version.Agent,
			Vendor:               "Quenyx",
			Description:          "QynRun automation execution (disabled by default)",
			Capabilities:         []string{"automation.runner", "automation.execution"},
			RequiredPermissions:  []string{"automation"},
			ConfigurationVersion: "1",
			Dangerous:            true,
			DefaultEnabled:       false,
			PermissionGate:       []string{"automation"},
			health:               "disabled",
		},
		{
			PluginKey:            "compliance",
			Name:                 "Compliance",
			Version:              version.Agent,
			Vendor:               "Quenyx",
			Description:          "Compliance evidence collection (disabled by default)",
			Capabilities:         []string{"compliance.evidence"},
			RequiredPermissions:  []string{"compliance"},
			ConfigurationVersion: "1",
			Dangerous:            true,
			DefaultEnabled:       false,
			PermissionGate:       []string{"compliance"},
			health:               "disabled",
		},
	}
}
