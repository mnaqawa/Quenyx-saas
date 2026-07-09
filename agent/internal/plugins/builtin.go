package plugins

// MonitoringPlugin collects CPU, memory, disk, load telemetry.
type MonitoringPlugin struct{}

func NewMonitoringPlugin() *MonitoringPlugin { return &MonitoringPlugin{} }

func (p *MonitoringPlugin) Name() string { return "monitoring" }

func (p *MonitoringPlugin) Capabilities() []string {
	return []string{"monitoring.telemetry", "monitoring.service_checks"}
}

func (p *MonitoringPlugin) Enabled() bool { return true }

func (p *MonitoringPlugin) Start() error { return nil }

func (p *MonitoringPlugin) Stop() error { return nil }

// InventoryPlugin collects hardware/software inventory for QynAsset.
type InventoryPlugin struct{}

func NewInventoryPlugin() *InventoryPlugin { return &InventoryPlugin{} }

func (p *InventoryPlugin) Name() string { return "asset-inventory" }

func (p *InventoryPlugin) Capabilities() []string {
	return []string{"asset.inventory", "asset.hardware", "asset.software"}
}

func (p *InventoryPlugin) Enabled() bool { return true }

func (p *InventoryPlugin) Start() error { return nil }

func (p *InventoryPlugin) Stop() error { return nil }

// AutomationPlugin is disabled by default — requires explicit permission + approval.
type AutomationPlugin struct{}

func NewAutomationPlugin() *AutomationPlugin { return &AutomationPlugin{} }

func (p *AutomationPlugin) Name() string { return "automation-runner" }

func (p *AutomationPlugin) Capabilities() []string {
	return []string{"automation.runner", "automation.execution"}
}

func (p *AutomationPlugin) Enabled() bool { return false }

func (p *AutomationPlugin) Start() error { return nil }

func (p *AutomationPlugin) Stop() error { return nil }
