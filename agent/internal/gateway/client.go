package gateway

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"time"

	"github.com/quenyx/agent/internal/config"
	"github.com/quenyx/agent/internal/version"
)

// Client posts agent API requests via QAG with optional failover retry.
type Client struct {
	PrimaryURL  string
	FailoverURL string
	HTTP        *http.Client
}

func NewClient(cfg *config.Config) *Client {
	failover := ""
	if cfg.FailoverGateway != nil {
		failover = cfg.FailoverGateway.EndpointURL
	}
	return &Client{
		PrimaryURL:  cfg.PlatformURL,
		FailoverURL: failover,
		HTTP:        &http.Client{Timeout: 30 * time.Second},
	}
}

type PostResult struct {
	StatusCode int
	Body       []byte
	Latency    time.Duration
	UsedURL    string
	Err        error
}

// PostJSON sends a JSON POST to /v1/agents/{id}/{suffix} with agent secret auth.
func (c *Client) PostJSON(agentID, secret, suffix string, body interface{}) PostResult {
	jsonBody, err := json.Marshal(body)
	if err != nil {
		return PostResult{Err: err}
	}

	start := time.Now()
	res := c.postOnce(c.PrimaryURL, agentID, secret, suffix, jsonBody)
	if res.Err != nil && c.FailoverURL != "" && c.FailoverURL != c.PrimaryURL {
		fallback := c.postOnce(c.FailoverURL, agentID, secret, suffix, jsonBody)
		if fallback.Err == nil {
			fallback.Latency = time.Since(start)
			return fallback
		}
	}
	res.Latency = time.Since(start)
	return res
}

func (c *Client) postOnce(baseURL, agentID, secret, suffix string, jsonBody []byte) PostResult {
	u, err := url.JoinPath(baseURL, "/v1/agents/", agentID, suffix)
	if err != nil {
		return PostResult{Err: fmt.Errorf("join url: %w", err)}
	}
	req, err := http.NewRequest(http.MethodPost, u, bytes.NewReader(jsonBody))
	if err != nil {
		return PostResult{Err: err}
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Agent-Secret", secret)
	req.Header.Set("X-Quenyx-Agent-Version", version.Agent)

	resp, err := c.HTTP.Do(req)
	if err != nil {
		return PostResult{Err: err, UsedURL: baseURL}
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(resp.Body)
	return PostResult{
		StatusCode: resp.StatusCode,
		Body:       body,
		UsedURL:    baseURL,
	}
}
