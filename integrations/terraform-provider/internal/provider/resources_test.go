package provider

import (
	"fmt"
	"os"
	"testing"

	"github.com/hashicorp/terraform-plugin-framework/providerserver"
	"github.com/hashicorp/terraform-plugin-go/tfprotov6"
	"github.com/hashicorp/terraform-plugin-testing/helper/resource"
	"github.com/hashicorp/terraform-plugin-testing/terraform"
)

// TestProjectAndMonitorLifecycle runs Terraform against the fake API: create, change and destroy a project and a
// monitor in its production environment. It needs Terraform, so it only runs with TF_ACC set.
func TestProjectAndMonitorLifecycle(t *testing.T) {
	if os.Getenv("TF_ACC") == "" {
		t.Skip("set TF_ACC to run Terraform")
	}
	api, server := newFakeAPI(t)
	config := func(name, interval string) string {
		return fmt.Sprintf(`
provider "buildpusher" {
  url   = %q
  token = "test-token"
}
resource "buildpusher_project" "shop" {
  name     = %q
  services = ["deploy", "monitoring"]
}
resource "buildpusher_monitor" "home" {
  project_id       = buildpusher_project.shop.id
  environment_id   = buildpusher_project.shop.environments["production"]
  name             = "Home page"
  request_url      = "https://shop.example.com/"
  interval_minutes = %s
}
`, server.URL, name, interval)
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: map[string]func() (tfprotov6.ProviderServer, error){
			"buildpusher": providerserver.NewProtocol6WithError(New("test")()),
		},
		Steps: []resource.TestStep{
			{
				Config: config("Shop", "5"),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("buildpusher_project.shop", "name", "Shop"),
					resource.TestCheckResourceAttr("buildpusher_project.shop", "environments.production", "project-1-production"),
					resource.TestCheckResourceAttr("buildpusher_monitor.home", "check_type", "http"),
					resource.TestCheckResourceAttr("buildpusher_monitor.home", "status_max", "399"),
				),
			},
			{
				Config: config("Shop EU", "1"),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("buildpusher_project.shop", "name", "Shop EU"),
					resource.TestCheckResourceAttr("buildpusher_monitor.home", "interval_minutes", "1"),
					resource.TestCheckResourceAttr("buildpusher_monitor.home", "health", "up"),
				),
			},
		},
		CheckDestroy: func(_ *terraform.State) error {
			if len(api.projects) != 0 || len(api.monitors) != 0 {
				return fmt.Errorf("left behind %d projects and %d monitors", len(api.projects), len(api.monitors))
			}
			return nil
		},
	})
}

// TestServerAndWebsiteLifecycle creates a server and a website on it, waiting for each to be set up, moves the
// website's settings, and destroys both.
func TestServerAndWebsiteLifecycle(t *testing.T) {
	if os.Getenv("TF_ACC") == "" {
		t.Skip("set TF_ACC to run Terraform")
	}
	api, server := newFakeAPI(t)
	config := func(retention string) string {
		return fmt.Sprintf(`
provider "buildpusher" {
  url   = %q
  token = "test-token"
}
resource "buildpusher_server" "web" {
  name        = "web-1"
  provider_id = 7
  type        = "app"
  region      = "fra1"
  size        = "s-1vcpu-1gb"
  image       = "ubuntu-24-04-x64"
}
resource "buildpusher_website" "shop" {
  name              = "Shop"
  url               = "shop.example.com"
  server_id         = buildpusher_server.web.id
  release_retention = %s
}
`, server.URL, retention)
	}
	resource.UnitTest(t, resource.TestCase{
		ProtoV6ProviderFactories: map[string]func() (tfprotov6.ProviderServer, error){
			"buildpusher": providerserver.NewProtocol6WithError(New("test")()),
		},
		Steps: []resource.TestStep{
			{
				Config: config("5"),
				Check: resource.ComposeAggregateTestCheckFunc(
					resource.TestCheckResourceAttr("buildpusher_server.web", "status", "active"),
					resource.TestCheckResourceAttr("buildpusher_server.web", "public_ip", "203.0.113.9"),
					resource.TestCheckResourceAttr("buildpusher_website.shop", "status", "active"),
					resource.TestCheckResourceAttr("buildpusher_website.shop", "health_check_path", "/"),
				),
			},
			{
				Config: config("8"),
				Check:  resource.TestCheckResourceAttr("buildpusher_website.shop", "release_retention", "8"),
			},
		},
		CheckDestroy: func(_ *terraform.State) error {
			if len(api.servers) != 0 || len(api.websites) != 0 {
				return fmt.Errorf("left behind %d servers and %d websites", len(api.servers), len(api.websites))
			}
			return nil
		},
	})
}
