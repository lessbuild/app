terraform {
  required_providers {
    buildpusher = { source = "lessbuild/buildpusher" }
  }
}

provider "buildpusher" {}

variable "provider_id" {
  description = "The cloud provider connection's ID, from Account → Providers."
  type        = number
}

resource "buildpusher_project" "shop" {
  name        = "Shop"
  description = "The online store"
  services    = ["deploy", "infrastructure", "monitoring", "analytics"]
}

resource "buildpusher_server" "web" {
  name        = "shop-web-1"
  provider_id = var.provider_id
  type        = "app"
  region      = "fra1"
  size        = "s-2vcpu-4gb"
  image       = "ubuntu-24-04-x64"
}

resource "buildpusher_website" "shop" {
  name           = "Shop"
  url            = "shop.example.com"
  server_id      = buildpusher_server.web.id
  environment_id = buildpusher_project.shop.environments["production"]
  self_healing   = true
}

resource "buildpusher_monitor" "home" {
  project_id       = buildpusher_project.shop.id
  environment_id   = buildpusher_project.shop.environments["production"]
  name             = "Home page"
  request_url      = "https://shop.example.com/"
  interval_minutes = 1
}
