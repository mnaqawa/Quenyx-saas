package plugins

import (
	"sort"
)

// Manager tracks plugin enablement and metadata for heartbeat reporting.
type Manager struct {
	plugins     []*Descriptor
	permissions map[string]bool
}

func NewManager(permissions []string) *Manager {
	pm := make(map[string]bool, len(permissions))
	for _, p := range permissions {
		pm[p] = true
	}
	m := &Manager{permissions: pm}
	for _, d := range DefaultRegistry() {
		cp := *d
		cp.SetEnabled(cp.IsGranted(pm))
		m.plugins = append(m.plugins, &cp)
	}
	return m
}

func (m *Manager) ApplyPermissions(permissions []string) {
	m.permissions = make(map[string]bool, len(permissions))
	for _, p := range permissions {
		m.permissions[p] = true
	}
	for _, p := range m.plugins {
		p.SetEnabled(p.IsGranted(m.permissions))
	}
}

func (m *Manager) All() []*Descriptor {
	return m.plugins
}

func (m *Manager) Enabled() []*Descriptor {
	var out []*Descriptor
	for _, p := range m.plugins {
		if p.Enabled() {
			out = append(out, p)
		}
	}
	return out
}

func (m *Manager) Disabled() []*Descriptor {
	var out []*Descriptor
	for _, p := range m.plugins {
		if !p.Enabled() {
			out = append(out, p)
		}
	}
	return out
}

func (m *Manager) Capabilities() []string {
	seen := map[string]bool{}
	var caps []string
	for _, p := range m.plugins {
		if !p.Enabled() {
			continue
		}
		for _, c := range p.Capabilities {
			if !seen[c] {
				seen[c] = true
				caps = append(caps, c)
			}
		}
	}
	sort.Strings(caps)
	return caps
}

func (m *Manager) PluginVersions() map[string]string {
	out := make(map[string]string, len(m.plugins))
	for _, p := range m.plugins {
		ver := p.Version
		if ver == "" {
			ver = "1.0.0"
		}
		out[p.PluginKey] = ver
	}
	return out
}

func (m *Manager) HeartbeatPlugins() []Meta {
	out := make([]Meta, 0, len(m.plugins))
	for _, p := range m.plugins {
		out = append(out, p.ToMeta())
	}
	return out
}

func (m *Manager) ByKey(key string) *Descriptor {
	for _, p := range m.plugins {
		if p.PluginKey == key {
			return p
		}
	}
	return nil
}

func (m *Manager) RecordPluginRun(key string, err error) {
	if p := m.ByKey(key); p != nil {
		p.RecordExecution(err)
	}
}
