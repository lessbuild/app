// Package client talks to BuildPusher's resources API (/api/v2) with an API token.
package client

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"
)

// ErrNotFound means the resource doesn't exist (or isn't visible to the token).
var ErrNotFound = errors.New("not found")

// Client calls the API.
type Client struct {
	BaseURL    string
	Token      string
	HTTPClient *http.Client
	UserAgent  string
}

// New returns a client for a BuildPusher address and API token.
func New(baseURL, token, userAgent string) *Client {
	return &Client{
		BaseURL:    strings.TrimRight(baseURL, "/"),
		Token:      token,
		HTTPClient: &http.Client{Timeout: 60 * time.Second},
		UserAgent:  userAgent,
	}
}

// APIError is an unsuccessful answer, with the validation messages when there are any.
type APIError struct {
	Status  int
	Message string
	Errors  map[string][]string
}

func (e *APIError) Error() string {
	if len(e.Errors) == 0 {
		return fmt.Sprintf("BuildPusher answered HTTP %d: %s", e.Status, e.Message)
	}
	parts := make([]string, 0, len(e.Errors))
	for field, messages := range e.Errors {
		parts = append(parts, field+": "+strings.Join(messages, " "))
	}
	return fmt.Sprintf("BuildPusher answered HTTP %d: %s (%s)", e.Status, e.Message, strings.Join(parts, "; "))
}

// Do sends a request with an optional JSON body and decodes the response's "data" into out (when out isn't nil).
func (c *Client) Do(ctx context.Context, method, path string, body, out any) error {
	var reader io.Reader
	if body != nil {
		encoded, err := json.Marshal(body)
		if err != nil {
			return err
		}
		reader = bytes.NewReader(encoded)
	}
	request, err := http.NewRequestWithContext(ctx, method, c.BaseURL+"/api/v2"+path, reader)
	if err != nil {
		return err
	}
	request.Header.Set("Authorization", "Bearer "+c.Token)
	request.Header.Set("Accept", "application/json")
	request.Header.Set("User-Agent", c.UserAgent)
	if body != nil {
		request.Header.Set("Content-Type", "application/json")
	}
	response, err := c.HTTPClient.Do(request)
	if err != nil {
		return err
	}
	defer response.Body.Close()
	payload, err := io.ReadAll(io.LimitReader(response.Body, 10<<20))
	if err != nil {
		return err
	}
	if response.StatusCode == http.StatusNotFound {
		return ErrNotFound
	}
	if response.StatusCode >= 300 {
		failure := &APIError{Status: response.StatusCode}
		var decoded struct {
			Message string              `json:"message"`
			Errors  map[string][]string `json:"errors"`
		}
		if json.Unmarshal(payload, &decoded) == nil {
			failure.Message, failure.Errors = decoded.Message, decoded.Errors
		}
		if failure.Message == "" {
			failure.Message = http.StatusText(response.StatusCode)
		}
		return failure
	}
	if out == nil || len(payload) == 0 {
		return nil
	}
	envelope := struct {
		Data json.RawMessage `json:"data"`
	}{}
	if err := json.Unmarshal(payload, &envelope); err != nil {
		return err
	}
	return json.Unmarshal(envelope.Data, out)
}

// Environment is one of a project's environments.
type Environment struct {
	ID   string `json:"id"`
	Name string `json:"name"`
	Slug string `json:"slug"`
}

// Project is a BuildPusher project.
type Project struct {
	ID           string        `json:"id,omitempty"`
	Name         string        `json:"name"`
	Slug         string        `json:"slug,omitempty"`
	Description  *string       `json:"description"`
	Services     []string      `json:"services"`
	Environments []Environment `json:"environments,omitempty"`
}

// Server is a server in one of the account's cloud providers.
type Server struct {
	ID             int64   `json:"id,omitempty"`
	Name           string  `json:"name"`
	ProviderID     int64   `json:"provider_id"`
	Type           string  `json:"type"`
	DatabaseEngine *string `json:"database_engine,omitempty"`
	Region         string  `json:"region"`
	Size           string  `json:"size"`
	Image          string  `json:"image"`
	PublicIP       *string `json:"public_ip,omitempty"`
	PrivateIP      *string `json:"private_ip,omitempty"`
	Status         string  `json:"status,omitempty"`
	Error          *string `json:"error,omitempty"`
}

// Website is a website on an app server.
type Website struct {
	ID                      int64   `json:"id,omitempty"`
	Name                    string  `json:"name"`
	URL                     string  `json:"url"`
	Description             *string `json:"description"`
	ServerID                int64   `json:"server_id"`
	EnvironmentID           *string `json:"environment_id"`
	ReleaseRetention        int64   `json:"release_retention,omitempty"`
	HealthCheckEnabled      bool    `json:"health_check_enabled"`
	HealthCheckPath         string  `json:"health_check_path,omitempty"`
	HealthMonitoringEnabled bool    `json:"health_monitoring_enabled"`
	SelfHealing             bool    `json:"self_healing"`
	Status                  string  `json:"status,omitempty"`
	Error                   *string `json:"error,omitempty"`
}

// Monitor is an uptime or other check in one of a project's environments.
type Monitor struct {
	ID             int64   `json:"id,omitempty"`
	Name           string  `json:"name"`
	EnvironmentID  string  `json:"environment_id"`
	CheckType      string  `json:"check_type"`
	RequestURL     *string `json:"request_url,omitempty"`
	Method         string  `json:"method,omitempty"`
	StatusMin      int64   `json:"status_min,omitempty"`
	StatusMax      int64   `json:"status_max,omitempty"`
	Hostname       *string `json:"hostname,omitempty"`
	TimeoutSeconds int64   `json:"timeout_seconds"`
	IntervalMins   int64   `json:"interval_minutes"`
	TriggerChecks  int64   `json:"trigger_checks"`
	RecoveryChecks int64   `json:"recovery_checks"`
	Enabled        bool    `json:"enabled"`
	Health         string  `json:"health,omitempty"`
}

// WaitFor polls until ready reports done, an error, or the timeout passes.
func WaitFor(ctx context.Context, timeout, every time.Duration, ready func() (bool, error)) error {
	deadline := time.Now().Add(timeout)
	for {
		done, err := ready()
		if err != nil || done {
			return err
		}
		if time.Now().After(deadline) {
			return fmt.Errorf("still not ready after %s", timeout)
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(every):
		}
	}
}
