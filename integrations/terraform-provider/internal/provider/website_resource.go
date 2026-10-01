package provider

import (
	"context"
	"errors"
	"fmt"
	"strconv"
	"time"

	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/booldefault"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/int64default"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringdefault"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/types"
	"github.com/lessbuild/terraform-provider-buildpusher/internal/client"
)

var (
	_ resource.Resource                = (*websiteResource)(nil)
	_ resource.ResourceWithImportState = (*websiteResource)(nil)
)

// NewWebsiteResource returns the buildpusher_website resource.
func NewWebsiteResource() resource.Resource { return &websiteResource{} }

type websiteResource struct{ api *client.Client }

type websiteModel struct {
	ID                      types.String `tfsdk:"id"`
	Name                    types.String `tfsdk:"name"`
	URL                     types.String `tfsdk:"url"`
	Description             types.String `tfsdk:"description"`
	ServerID                types.Int64  `tfsdk:"server_id"`
	EnvironmentID           types.String `tfsdk:"environment_id"`
	ReleaseRetention        types.Int64  `tfsdk:"release_retention"`
	HealthCheckEnabled      types.Bool   `tfsdk:"health_check_enabled"`
	HealthCheckPath         types.String `tfsdk:"health_check_path"`
	HealthMonitoringEnabled types.Bool   `tfsdk:"health_monitoring_enabled"`
	SelfHealing             types.Bool   `tfsdk:"self_healing"`
	Status                  types.String `tfsdk:"status"`
}

func (r *websiteResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_website"
}

func (r *websiteResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	resp.Schema = schema.Schema{
		Description: "A website on one of your app servers: its domain, database and web server settings. Creating or moving one waits until it's set up.",
		Attributes: map[string]schema.Attribute{
			"id":                        schema.StringAttribute{Computed: true, PlanModifiers: []planmodifier.String{stringplanmodifier.UseStateForUnknown()}},
			"name":                      schema.StringAttribute{Required: true},
			"url":                       schema.StringAttribute{Required: true, Description: "The primary hostname, such as shop.example.com."},
			"description":               schema.StringAttribute{Optional: true},
			"server_id":                 schema.Int64Attribute{Required: true},
			"environment_id":            schema.StringAttribute{Optional: true, Description: "The project environment it belongs to."},
			"release_retention":         schema.Int64Attribute{Optional: true, Computed: true, Default: int64default.StaticInt64(5)},
			"health_check_enabled":      schema.BoolAttribute{Optional: true, Computed: true, Default: booldefault.StaticBool(true)},
			"health_check_path":         schema.StringAttribute{Optional: true, Computed: true, Default: stringdefault.StaticString("/")},
			"health_monitoring_enabled": schema.BoolAttribute{Optional: true, Computed: true, Default: booldefault.StaticBool(true)},
			"self_healing":              schema.BoolAttribute{Optional: true, Computed: true, Default: booldefault.StaticBool(false)},
			"status":                    schema.StringAttribute{Computed: true},
		},
	}
}

func (r *websiteResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.api = configureClient(req.ProviderData, resp.Diagnostics.AddError)
}

func (r *websiteResource) body(plan websiteModel) map[string]any {
	return map[string]any{
		"name": plan.Name.ValueString(), "url": plan.URL.ValueString(), "description": stringPointer(plan.Description),
		"server_id": plan.ServerID.ValueInt64(), "environment_id": stringPointer(plan.EnvironmentID),
		"release_retention": plan.ReleaseRetention.ValueInt64(), "health_check_enabled": plan.HealthCheckEnabled.ValueBool(),
		"health_check_path": plan.HealthCheckPath.ValueString(), "health_monitoring_enabled": plan.HealthMonitoringEnabled.ValueBool(),
		"self_healing": plan.SelfHealing.ValueBool(),
	}
}

func (r *websiteResource) fill(website client.Website, model *websiteModel) {
	model.ID = types.StringValue(strconv.FormatInt(website.ID, 10))
	model.Name = types.StringValue(website.Name)
	model.URL = types.StringValue(website.URL)
	model.Description = optionalString(website.Description)
	model.ServerID = types.Int64Value(website.ServerID)
	model.EnvironmentID = optionalString(website.EnvironmentID)
	model.ReleaseRetention = types.Int64Value(website.ReleaseRetention)
	model.HealthCheckEnabled = types.BoolValue(website.HealthCheckEnabled)
	model.HealthCheckPath = types.StringValue(website.HealthCheckPath)
	model.HealthMonitoringEnabled = types.BoolValue(website.HealthMonitoringEnabled)
	model.SelfHealing = types.BoolValue(website.SelfHealing)
	model.Status = types.StringValue(website.Status)
}

// wait polls the website until it's set up or setting it up failed.
func (r *websiteResource) wait(ctx context.Context, website *client.Website) error {
	id := strconv.FormatInt(website.ID, 10)
	return client.WaitFor(ctx, 30*time.Minute, 10*time.Second, func() (bool, error) {
		if err := r.api.Do(ctx, "GET", "/websites/"+id, nil, website); err != nil {
			return false, err
		}
		if website.Status == "failed" {
			reason := "no reason given"
			if website.Error != nil {
				reason = *website.Error
			}
			return false, fmt.Errorf("setting up the website failed: %s", reason)
		}
		return website.Status == "active", nil
	})
}

func (r *websiteResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	var plan websiteModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	var website client.Website
	if err := r.api.Do(ctx, "POST", "/websites", r.body(plan), &website); err != nil {
		resp.Diagnostics.AddError("Couldn't create the website", err.Error())
		return
	}
	r.fill(website, &plan)
	resp.Diagnostics.Append(resp.State.Set(ctx, plan)...)
	err := r.wait(ctx, &website)
	r.fill(website, &plan)
	resp.Diagnostics.Append(resp.State.Set(ctx, plan)...)
	if err != nil {
		resp.Diagnostics.AddError("The website wasn't set up", err.Error())
	}
}

func (r *websiteResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	var state websiteModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	var website client.Website
	err := r.api.Do(ctx, "GET", "/websites/"+state.ID.ValueString(), nil, &website)
	if errors.Is(err, client.ErrNotFound) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		resp.Diagnostics.AddError("Couldn't read the website", err.Error())
		return
	}
	r.fill(website, &state)
	resp.Diagnostics.Append(resp.State.Set(ctx, state)...)
}

func (r *websiteResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	var plan, state websiteModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	var website client.Website
	if err := r.api.Do(ctx, "PUT", "/websites/"+state.ID.ValueString(), r.body(plan), &website); err != nil {
		resp.Diagnostics.AddError("Couldn't change the website", err.Error())
		return
	}
	err := r.wait(ctx, &website)
	r.fill(website, &plan)
	resp.Diagnostics.Append(resp.State.Set(ctx, plan)...)
	if err != nil {
		resp.Diagnostics.AddError("The website wasn't set up again", err.Error())
	}
}

func (r *websiteResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	var state websiteModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	if err := r.api.Do(ctx, "DELETE", "/websites/"+state.ID.ValueString(), nil, nil); err != nil && !errors.Is(err, client.ErrNotFound) {
		resp.Diagnostics.AddError("Couldn't delete the website", err.Error())
	}
}

func (r *websiteResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	resource.ImportStatePassthroughID(ctx, path.Root("id"), req, resp)
}
