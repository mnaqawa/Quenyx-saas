// Package plugins provides the Quenyx Platform Agent plugin manager.
// Plugins are enabled according to subscription ∩ permissions ∩ platform policy.
package plugins

import "log"

// Plugin is a capability module loaded by the core agent.
type Plugin interface {
	Name() string
	Capabilities() []string
	Enabled() bool
	Start() error
	Stop() error
}

// Manager loads and manages capability plugins.
type Manager struct {
	plugins []Plugin
	allowed map[string]bool
}

func NewManager(allowedCapabilities []string) *Manager {
	allowed := make(map[string]bool, len(allowedCapabilities))
	for _, c := range allowedCapabilities {
		allowed[c] = true
	}
	return &Manager{allowed: allowed}
}

func (m *Manager) Register(p Plugin) {
	m.plugins = append(m.plugins, p)
}

func (m *Manager) StartAll() {
	for _, p := range m.plugins {
		enabled := false
		for _, cap := range p.Capabilities() {
			if m.allowed[cap] {
				enabled = true
				break
			}
		}
		if !enabled {
			log.Printf("[qpa] plugin %s skipped (capability not granted)", p.Name())
			continue
		}
		if err := p.Start(); err != nil {
			log.Printf("[qpa] plugin %s start failed: %v", p.Name(), err)
		} else {
			log.Printf("[qpa] plugin %s started", p.Name())
		}
	}
}
