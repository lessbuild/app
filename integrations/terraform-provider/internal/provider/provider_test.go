package provider

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"sync"
	"testing"

	"github.com/hashicorp/terraform-plugin-framework/providerserver"
	"github.com/hashicorp/terraform-plugin-go/tfprotov6"
)

func TestTheSchemaIsValid(t *testing.T) {
	server := providerserver.NewProtocol6(New("test")())()
	response, err := server.GetProviderSchema(context.Background(), &tfprotov6.GetProviderSchemaRequest{})
	if err != nil {
		t.Fatal(err)
	}
	for _, diagnostic := range response.Diagnostics {
		t.Errorf("%s: %s", diagnostic.Summary, diagnostic.Detail)
	}
	for _, name := range []string{"buildpusher_project", "buildpusher_server", "buildpusher_website", "buildpusher_monitor"} {
		if _, ok := response.ResourceSchemas[name]; !ok {
			t.Errorf("missing resource %s", name)
		}
	}
}

// fakeAPI is an in-memory BuildPusher resources API for the end-to-end test.
type fakeAPI struct {
	mu       sync.Mutex
	projects map[string]map[string]any
	monitors map[string]map[string]any
	servers  map[string]map[string]any
	websites map[string]map[string]any
	next     int
}

// item answers one server or website: it reports provisioning on creation and active from the first read, like the
// real API once setup finishes.
func (f *fakeAPI) item(w http.ResponseWriter, r *http.Request, store map[string]map[string]any, parts []string, body map[string]any, respond func(int, any)) {
	if len(parts) == 1 && r.Method == "POST" {
		f.next++
		body["id"], body["status"] = f.next, "provisioning"
		if parts[0] == "servers" {
			body["public_ip"] = "203.0.113.9"
		}
		store[fmt.Sprint(f.next)] = body
		respond(201, body)
		return
	}
	item, ok := store[parts[len(parts)-1]]
	if !ok {
		w.WriteHeader(http.StatusNotFound)
		return
	}
	switch r.Method {
	case "PUT":
		body["id"], body["status"] = item["id"], "provisioning"
		store[parts[1]] = body
		respond(200, body)
	case "DELETE":
		delete(store, parts[1])
		respond(204, nil)
	default:
		item["status"] = "active"
		respond(200, item)
	}
}

func (f *fakeAPI) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if r.Header.Get("Authorization") != "Bearer test-token" {
		w.WriteHeader(http.StatusUnauthorized)
		return
	}
	var body map[string]any
	_ = json.NewDecoder(r.Body).Decode(&body)
	parts := strings.Split(strings.TrimPrefix(r.URL.Path, "/api/v2/"), "/")
	respond := func(status int, data any) {
		w.WriteHeader(status)
		if data != nil {
			_ = json.NewEncoder(w).Encode(map[string]any{"data": data})
		}
	}
	switch {
	case parts[0] == "servers":
		f.item(w, r, f.servers, parts, body, respond)
	case parts[0] == "websites":
		f.item(w, r, f.websites, parts, body, respond)
	case len(parts) == 1 && r.Method == "POST":
		f.next++
		id := fmt.Sprintf("project-%d", f.next)
		project := map[string]any{"id": id, "name": body["name"], "slug": "shop", "description": body["description"], "services": body["services"],
			"environments": []map[string]string{{"id": id + "-production", "name": "Production", "slug": "production"}}}
		f.projects[id] = project
		respond(201, project)
	case len(parts) == 2:
		project, ok := f.projects[parts[1]]
		if !ok {
			w.WriteHeader(http.StatusNotFound)
			return
		}
		switch r.Method {
		case "PUT":
			project["name"], project["description"] = body["name"], body["description"]
			if services, ok := body["services"]; ok {
				project["services"] = services
			}
			respond(200, project)
		case "DELETE":
			delete(f.projects, parts[1])
			respond(204, nil)
		default:
			respond(200, project)
		}
	case len(parts) == 3 && r.Method == "POST":
		f.next++
		body["id"], body["health"] = f.next, "unknown"
		f.monitors[fmt.Sprint(f.next)] = body
		respond(201, body)
	case len(parts) == 4:
		monitor, ok := f.monitors[parts[3]]
		if !ok {
			w.WriteHeader(http.StatusNotFound)
			return
		}
		switch r.Method {
		case "PUT":
			body["id"], body["health"] = monitor["id"], "up"
			f.monitors[parts[3]] = body
			respond(200, body)
		case "DELETE":
			delete(f.monitors, parts[3])
			respond(204, nil)
		default:
			respond(200, monitor)
		}
	default:
		w.WriteHeader(http.StatusNotFound)
	}
}

func TestMain(m *testing.M) {
	os.Exit(m.Run())
}

// newFakeAPI starts the fake API.
func newFakeAPI(t *testing.T) (*fakeAPI, *httptest.Server) {
	api := &fakeAPI{projects: map[string]map[string]any{}, monitors: map[string]map[string]any{}, servers: map[string]map[string]any{}, websites: map[string]map[string]any{}}
	server := httptest.NewServer(api)
	t.Cleanup(server.Close)
	return api, server
}
