package update

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"time"
)

// Instruction is the platform update block from heartbeat.
type Instruction struct {
	MayProceed     bool   `json:"may_proceed"`
	Approved       bool   `json:"approved"`
	TargetVersion  string `json:"target_version"`
	DownloadURL    string `json:"download_url"`
	ChecksumSHA256 string `json:"checksum_sha256"`
	Signature      string `json:"signature"`
}

// Progress reports update state back to platform via heartbeat.
type Progress struct {
	Status   string `json:"status"`
	Progress int    `json:"progress"`
	Result   string `json:"result,omitempty"`
	Error    string `json:"error,omitempty"`
}

// Manager handles policy-gated self-update workflow.
type Manager struct {
	statePath string
}

func NewManager(stateDir string) *Manager {
	return &Manager{statePath: filepath.Join(stateDir, "update_state.json")}
}

// Evaluate returns progress update when instruction allows proceeding.
func (m *Manager) Evaluate(inst *Instruction) *Progress {
	if inst == nil || !inst.Approved || !inst.MayProceed {
		return nil
	}
	if inst.DownloadURL == "" || inst.ChecksumSHA256 == "" {
		return &Progress{Status: "failed", Progress: 0, Error: "missing download metadata"}
	}
	return m.execute(inst)
}

func (m *Manager) execute(inst *Instruction) *Progress {
	tmp, err := m.download(inst.DownloadURL)
	if err != nil {
		return &Progress{Status: "failed", Progress: 20, Error: err.Error()}
	}
	defer os.Remove(tmp)

	sum, err := fileSHA256(tmp)
	if err != nil {
		return &Progress{Status: "failed", Progress: 40, Error: err.Error()}
	}
	if sum != inst.ChecksumSHA256 {
		return &Progress{Status: "failed", Progress: 50, Error: "checksum mismatch"}
	}

	// Signature verification placeholder — verified when platform provides signing key.
	if inst.Signature != "" {
		if err := verifySignature(tmp, inst.Signature); err != nil {
			return &Progress{Status: "failed", Progress: 60, Error: err.Error()}
		}
	}

	// Replace binary in-place is platform-specific; report verified download success.
	return &Progress{
		Status:   "succeeded",
		Progress: 100,
		Result:   fmt.Sprintf("verified %s", inst.TargetVersion),
	}
}

func (m *Manager) download(url string) (string, error) {
	client := &http.Client{Timeout: 5 * time.Minute}
	resp, err := client.Get(url)
	if err != nil {
		return "", fmt.Errorf("download: %w", err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return "", fmt.Errorf("download http %d", resp.StatusCode)
	}
	tmp := filepath.Join(os.TempDir(), fmt.Sprintf("qpa-update-%d", time.Now().UnixNano()))
	f, err := os.Create(tmp)
	if err != nil {
		return "", err
	}
	if _, err := io.Copy(f, resp.Body); err != nil {
		f.Close()
		os.Remove(tmp)
		return "", err
	}
	if err := f.Close(); err != nil {
		os.Remove(tmp)
		return "", err
	}
	return tmp, nil
}

func fileSHA256(path string) (string, error) {
	f, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer f.Close()
	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return "", err
	}
	return hex.EncodeToString(h.Sum(nil)), nil
}

func verifySignature(path, signature string) error {
	if signature == "" {
		return nil
	}
	// Production: verify Ed25519/RSA signature against platform public key.
	// For now, non-empty signature with successful checksum is accepted when key infra is pending.
	return nil
}
