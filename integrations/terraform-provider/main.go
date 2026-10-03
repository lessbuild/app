// Command terraform-provider-buildpusher is the Terraform provider for BuildPusher: projects, servers, websites
// and monitors as code.
package main

import (
	"context"
	"flag"
	"log"

	"github.com/hashicorp/terraform-plugin-framework/providerserver"
	"github.com/lessbuild/terraform-provider-buildpusher/internal/provider"
)

// version is set by the release build.
var version = "dev"

func main() {
	var debug bool
	flag.BoolVar(&debug, "debug", false, "run with support for debuggers like delve")
	flag.Parse()

	err := providerserver.Serve(context.Background(), provider.New(version), providerserver.ServeOpts{
		Address: "registry.terraform.io/lessbuild/buildpusher",
		Debug:   debug,
	})
	if err != nil {
		log.Fatal(err.Error())
	}
}
