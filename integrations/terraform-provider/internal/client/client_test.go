package client

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func TestDoSendsTheTokenAndDecodesData(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer secret" || r.URL.Path != "/api/v2/projects/p1" {
			t.Errorf("unexpected request %s %s", r.Header.Get("Authorization"), r.URL.Path)
		}
		_, _ = w.Write([]byte(`{"data":{"id":"p1","name":"Shop","services":["deploy"]}}`))
	}))
	defer server.Close()

	var project Project
	if err := New(server.URL+"/", "secret", "test").Do(context.Background(), "GET", "/projects/p1", nil, &project); err != nil {
		t.Fatal(err)
	}
	if project.Name != "Shop" || len(project.Services) != 1 {
		t.Fatalf("decoded %+v", project)
	}
}

func TestDoReportsMissingAndInvalid(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == "GET" {
			w.WriteHeader(http.StatusNotFound)
			return
		}
		w.WriteHeader(http.StatusUnprocessableEntity)
		_, _ = w.Write([]byte(`{"message":"The name field is required.","errors":{"name":["The name field is required."]}}`))
	}))
	defer server.Close()
	api := New(server.URL, "secret", "test")

	if err := api.Do(context.Background(), "GET", "/projects/x", nil, nil); !errors.Is(err, ErrNotFound) {
		t.Fatalf("expected ErrNotFound, got %v", err)
	}
	err := api.Do(context.Background(), "POST", "/projects", map[string]any{}, nil)
	var apiError *APIError
	if !errors.As(err, &apiError) || apiError.Status != 422 || !strings.Contains(err.Error(), "name: The name field is required.") {
		t.Fatalf("expected a validation error, got %v", err)
	}
}

func TestWaitForStopsWhenReadyOrFailed(t *testing.T) {
	calls := 0
	err := WaitFor(context.Background(), time.Second, time.Millisecond, func() (bool, error) {
		calls++
		return calls == 3, nil
	})
	if err != nil || calls != 3 {
		t.Fatalf("calls %d, err %v", calls, err)
	}
	if err := WaitFor(context.Background(), 5*time.Millisecond, time.Millisecond, func() (bool, error) { return false, nil }); err == nil {
		t.Fatal("expected a timeout")
	}
}
