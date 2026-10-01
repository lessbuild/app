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
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/int64planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/types"
	"github.com/lessbuild/terraform-provider-buildpusher/internal/client"
)

var (
	_ resource.Resource                = (*serverResource)(nil)
	_ resource.ResourceWithImportState = (*serverResource)(nil)
)

// NewServerResource returns the buildpusher_server resource.
func NewServerResource() resource.Resource { return &serverResource{} }

type serverResource struct{ api *client.Client }

type serverModel struct {
	ID             types.String `tfsdk:"id"`
	Name           types.String `tfsdk:"name"`
	ProviderID     types.Int64  `tfsdk:"provider_id"`
	Type           types.String `tfsdk:"type"`
	DatabaseEngine types.String `tfsdk:"database_engine"`
	Region         types.String `tfsdk:"region"`
	Size           types.String `tfsdk:"size"`
	Image          types.String `tfsdk:"image"`
	PublicIP       types.String `tfsdk:"public_ip"`
	PrivateIP      types.String `tfsdk:"private_ip"`
	Status         types.String `tfsdk:"status"`
}

func (r *serverResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_server"
}

func (r *serverResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	replace := []planmodifier.String{stringplanmodifier.RequiresReplace()}
	resp.Schema = schema.Schema{
		Description: "A server in one of your cloud providers (connected under Account → Providers), set up by BuildPusher. Creating one waits until it's ready, usually about ten minutes; changing anything replaces it.",
		Attributes: map[string]schema.Attribute{
			"id":              schema.StringAttribute{Computed: true, PlanModifiers: []planmodifier.String{stringplanmodifier.UseStateForUnknown()}},
			"name":            schema.StringAttribute{Required: true, PlanModifiers: replace},
			"provider_id":     schema.Int64Attribute{Required: true, PlanModifiers: []planmodifier.Int64{int64planmodifier.RequiresReplace()}, Description: "The cloud provider connection's ID."},
			"type":            schema.StringAttribute{Required: true, PlanModifiers: replace, Description: "app, web, worker, cache, database or load-balancer."},
			"database_engine": schema.StringAttribute{Optional: true, PlanModifiers: replace, Description: "mysql or postgres, for database servers."},
			"region":          schema.StringAttribute{Required: true, PlanModifiers: replace},
			"size":            schema.StringAttribute{Required: true, PlanModifiers: replace},
			"image":           schema.StringAttribute{Required: true, PlanModifiers: replace},
			"public_ip":       schema.StringAttribute{Computed: true, PlanModifiers: []planmodifier.String{stringplanmodifier.UseStateForUnknown()}},
			"private_ip":      schema.StringAttribute{Computed: true, PlanModifiers: []planmodifier.String{stringplanmodifier.UseStateForUnknown()}},
			"status":          schema.StringAttribute{Computed: true},
		},
	}
}

func (r *serverResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.api = configureClient(req.ProviderData, resp.Diagnostics.AddError)
}

func (r *serverResource) fill(server client.Server, model *serverModel) {
	model.ID = types.StringValue(strconv.FormatInt(server.ID, 10))
	model.Name = types.StringValue(server.Name)
	model.ProviderID = types.Int64Value(server.ProviderID)
	model.Type = types.StringValue(server.Type)
	model.DatabaseEngine = optionalString(server.DatabaseEngine)
	model.Region = types.StringValue(server.Region)
	model.Size = types.StringValue(server.Size)
	model.Image = types.StringValue(server.Image)
	model.PublicIP = optionalString(server.PublicIP)
	model.PrivateIP = optionalString(server.PrivateIP)
	model.Status = types.StringValue(server.Status)
}

func (r *serverResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	var plan serverModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	var server client.Server
	err := r.api.Do(ctx, "POST", "/servers", map[string]any{
		"name": plan.Name.ValueString(), "provider_id": plan.ProviderID.ValueInt64(), "type": plan.Type.ValueString(),
		"database_engine": stringPointer(plan.DatabaseEngine), "region": plan.Region.ValueString(), "size": plan.Size.ValueString(), "image": plan.Image.ValueString(),
	}, &server)
	if err != nil {
		resp.Diagnostics.AddError("Couldn't create the server", err.Error())
		return
	}
	// Save it straight away, so a failed wait doesn't leave a server Terraform doesn't know about.
	r.fill(server, &plan)
	resp.Diagnostics.Append(resp.State.Set(ctx, plan)...)
	err = client.WaitFor(ctx, 45*time.Minute, 15*time.Second, func() (bool, error) {
		if err := r.api.Do(ctx, "GET", "/servers/"+plan.ID.ValueString(), nil, &server); err != nil {
			return false, err
		}
		if server.Status == "failed" {
			reason := "no reason given"
			if server.Error != nil {
				reason = *server.Error
			}
			return false, fmt.Errorf("setting up the server failed: %s", reason)
		}
		return server.Status == "active", nil
	})
	r.fill(server, &plan)
	resp.Diagnostics.Append(resp.State.Set(ctx, plan)...)
	if err != nil {
		resp.Diagnostics.AddError("The server didn't become ready", err.Error())
	}
}

func (r *serverResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	var state serverModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	var server client.Server
	err := r.api.Do(ctx, "GET", "/servers/"+state.ID.ValueString(), nil, &server)
	if errors.Is(err, client.ErrNotFound) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		resp.Diagnostics.AddError("Couldn't read the server", err.Error())
		return
	}
	r.fill(server, &state)
	resp.Diagnostics.Append(resp.State.Set(ctx, state)...)
}

func (r *serverResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	// Every argument replaces the server, so there's nothing to change in place.
	var plan serverModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(resp.State.Set(ctx, plan)...)
}

func (r *serverResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	var state serverModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	if err := r.api.Do(ctx, "DELETE", "/servers/"+state.ID.ValueString(), nil, nil); err != nil && !errors.Is(err, client.ErrNotFound) {
		resp.Diagnostics.AddError("Couldn't delete the server", err.Error())
	}
}

func (r *serverResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	resource.ImportStatePassthroughID(ctx, path.Root("id"), req, resp)
}
