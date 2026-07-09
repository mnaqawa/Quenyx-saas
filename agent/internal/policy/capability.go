package policy

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"sort"
)

// CapabilityHash matches Laravel AgentPolicyService::capabilityHash (sorted JSON array).
func CapabilityHash(capabilities []string) string {
	caps := append([]string(nil), capabilities...)
	sort.Strings(caps)
	b, err := json.Marshal(caps)
	if err != nil {
		return ""
	}
	sum := sha256.Sum256(b)
	return hex.EncodeToString(sum[:])
}
