// Package provider is the BuildPusher Terraform provider.
package provider

import (
	"context"
	"os"

	"github.com/hashicorp/terraform-plugin-framework/datasource"
	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/provider"
	"github.com/hashicorp/terraform-plugin-framework/provider/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/types"
	"github.com/lessbuild/terraform-provider-buildpusher/internal/client"
)

var _ provider.Provider = (*buildPusherProvider)(nil)

// New returns a constructor for the provider at a version.
func New(version string) func() provider.Provider {
	return func() provider.Provider {
		return &buildPusherProvider{version: version}
	}
}

type buildPusherProvider struct {
	version string
}

type providerModel struct {
	URL   types.String `tfsdk:"url"`
	Token types.String `tfsdk:"token"`
}

func (p *buildPusherProvider) Metadata(_ context.Context, _ provider.MetadataRequest, resp *provider.MetadataResponse) {
	resp.TypeName = "buildpusher"
	resp.Version = p.version
}

func (p *buildPusherProvider) Schema(_ context.Context, _ provider.SchemaRequest, resp *provider.SchemaResponse) {
	resp.Schema = schema.Schema{
		Description: "Manage BuildPusher projects, servers, websites and monitors. Servers and websites run in your own cloud accounts.",
		Attributes: map[string]schema.Attribute{
			"url": schema.StringAttribute{
				Optional:    true,
				Description: "BuildPusher's address. Defaults to BUILDPUSHER_URL, then https://buildpusher.com.",
			},
			"token": schema.StringAttribute{
				Optional:    true,
				Sensitive:   true,
				Description: "An API token (Account → API tokens) with the scopes for what you manage. Defaults to BUILDPUSHER_TOKEN.",
			},
		},
	}
}

func (p *buildPusherProvider) Configure(ctx context.Context, req provider.ConfigureRequest, resp *provider.ConfigureResponse) {
	var config providerModel
	resp.Diagnostics.Append(req.Config.Get(ctx, &config)...)
	if resp.Diagnostics.HasError() {
		return
	}
	url := os.Getenv("BUILDPUSHER_URL")
	if !config.URL.IsNull() && config.URL.ValueString() != "" {
		url = config.URL.ValueString()
	}
	if url == "" {
		url = "https://buildpusher.com"
	}
	token := os.Getenv("BUILDPUSHER_TOKEN")
	if !config.Token.IsNull() && config.Token.ValueString() != "" {
		token = config.Token.ValueString()
	}
	if token == "" {
		resp.Diagnostics.AddAttributeError(path.Root("token"), "Missing API token", "Set token in the provider block or BUILDPUSHER_TOKEN. Create one under Account → API tokens.")
		return
	}
	api := client.New(url, token, "terraform-provider-buildpusher/"+p.version)
	resp.ResourceData = api
	resp.DataSourceData = api
}

func (p *buildPusherProvider) Resources(_ context.Context) []func() resource.Resource {
	return []func() resource.Resource{NewProjectResource, NewServerResource, NewWebsiteResource, NewMonitorResource}
}

func (p *buildPusherProvider) DataSources(_ context.Context) []func() datasource.DataSource {
	return nil
}

// configureClient takes the provider's client in a resource's Configure.
func configureClient(data any, add func(summary, detail string)) *client.Client {
	if data == nil {
		return nil
	}
	api, ok := data.(*client.Client)
	if !ok {
		add("Unexpected provider data", "The provider wasn't configured as expected.")
		return nil
	}
	return api
}

// optionalString turns a nullable string into a Terraform value.
func optionalString(value *string) types.String {
	if value == nil {
		return types.StringNull()
	}
	return types.StringValue(*value)
}

// stringPointer turns a Terraform value into a nullable string.
func stringPointer(value types.String) *string {
	if value.IsNull() || value.IsUnknown() {
		return nil
	}
	text := value.ValueString()
	return &text
}
