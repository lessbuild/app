package provider

import (
	"context"
	"errors"
	"strconv"
	"strings"

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
	_ resource.Resource                = (*monitorResource)(nil)
	_ resource.ResourceWithImportState = (*monitorResource)(nil)
)

// NewMonitorResource returns the buildpusher_monitor resource.
func NewMonitorResource() resource.Resource { return &monitorResource{} }

type monitorResource struct{ api *client.Client }

type monitorModel struct {
	ID             types.String `tfsdk:"id"`
	ProjectID      types.String `tfsdk:"project_id"`
	EnvironmentID  types.String `tfsdk:"environment_id"`
	Name           types.String `tfsdk:"name"`
	CheckType      types.String `tfsdk:"check_type"`
	RequestURL     types.String `tfsdk:"request_url"`
	Method         types.String `tfsdk:"method"`
	StatusMin      types.Int64  `tfsdk:"status_min"`
	StatusMax      types.Int64  `tfsdk:"status_max"`
	Hostname       types.String `tfsdk:"hostname"`
	TimeoutSeconds types.Int64  `tfsdk:"timeout_seconds"`
	Interval       types.Int64  `tfsdk:"interval_minutes"`
	TriggerChecks  types.Int64  `tfsdk:"trigger_checks"`
	RecoveryChecks types.Int64  `tfsdk:"recovery_checks"`
	Enabled        types.Bool   `tfsdk:"enabled"`
	Health         types.String `tfsdk:"health"`
}

func (r *monitorResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_monitor"
}

func (r *monitorResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	replace := []planmodifier.String{stringplanmodifier.RequiresReplace()}
	resp.Schema = schema.Schema{
		Description: "An uptime check in one of a project's environments: an HTTP request (the default), or a TLS certificate or TCP port check on a hostname. Deleting it archives it and keeps its history.",
		Attributes: map[string]schema.Attribute{
			"id":               schema.StringAttribute{Computed: true, PlanModifiers: []planmodifier.String{stringplanmodifier.UseStateForUnknown()}},
			"project_id":       schema.StringAttribute{Required: true, PlanModifiers: replace},
			"environment_id":   schema.StringAttribute{Required: true},
			"name":             schema.StringAttribute{Required: true},
			"check_type":       schema.StringAttribute{Optional: true, Computed: true, Default: stringdefault.StaticString("http"), PlanModifiers: replace, Description: "http, tls or tcp."},
			"request_url":      schema.StringAttribute{Optional: true, Description: "For http checks."},
			"method":           schema.StringAttribute{Optional: true, Computed: true, Default: stringdefault.StaticString("GET")},
			"status_min":       schema.Int64Attribute{Optional: true, Computed: true, Default: int64default.StaticInt64(200)},
			"status_max":       schema.Int64Attribute{Optional: true, Computed: true, Default: int64default.StaticInt64(399)},
			"hostname":         schema.StringAttribute{Optional: true, Description: "For tls and tcp checks."},
			"timeout_seconds":  schema.Int64Attribute{Optional: true, Computed: true, Default: int64default.StaticInt64(10)},
			"interval_minutes": schema.Int64Attribute{Optional: true, Computed: true, Default: int64default.StaticInt64(5)},
			"trigger_checks":   schema.Int64Attribute{Optional: true, Computed: true, Default: int64default.StaticInt64(2)},
			"recovery_checks":  schema.Int64Attribute{Optional: true, Computed: true, Default: int64default.StaticInt64(2)},
			"enabled":          schema.BoolAttribute{Optional: true, Computed: true, Default: booldefault.StaticBool(true)},
			"health":           schema.StringAttribute{Computed: true},
		},
	}
}

func (r *monitorResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.api = configureClient(req.ProviderData, resp.Diagnostics.AddError)
}

func (r *monitorResource) body(plan monitorModel) map[string]any {
	body := map[string]any{
		"name": plan.Name.ValueString(), "environment_id": plan.EnvironmentID.ValueString(), "check_type": plan.CheckType.ValueString(),
		"timeout_seconds": plan.TimeoutSeconds.ValueInt64(), "interval_minutes": plan.Interval.ValueInt64(),
		"trigger_checks": plan.TriggerChecks.ValueInt64(), "recovery_checks": plan.RecoveryChecks.ValueInt64(), "enabled": plan.Enabled.ValueBool(),
	}
	switch plan.CheckType.ValueString() {
	case "http":
		body["request_url"] = plan.RequestURL.ValueString()
		body["method"] = plan.Method.ValueString()
		body["status_min"] = plan.StatusMin.ValueInt64()
		body["status_max"] = plan.StatusMax.ValueInt64()
	case "tls":
		body["hostname"] = plan.Hostname.ValueString()
		body["tls_port"] = 443
		body["tls_expiry_days"] = 14
	case "tcp":
		body["hostname"] = plan.Hostname.ValueString()
	}
	return body
}

func (r *monitorResource) fill(monitor client.Monitor, model *monitorModel) {
	model.ID = types.StringValue(strconv.FormatInt(monitor.ID, 10))
	model.EnvironmentID = types.StringValue(monitor.EnvironmentID)
	model.Name = types.StringValue(monitor.Name)
	model.CheckType = types.StringValue(monitor.CheckType)
	model.RequestURL = optionalString(monitor.RequestURL)
	if monitor.Method != "" {
		model.Method = types.StringValue(monitor.Method)
	}
	model.StatusMin = types.Int64Value(monitor.StatusMin)
	model.StatusMax = types.Int64Value(monitor.StatusMax)
	model.Hostname = optionalString(monitor.Hostname)
	model.TimeoutSeconds = types.Int64Value(monitor.TimeoutSeconds)
	model.Interval = types.Int64Value(monitor.IntervalMins)
	model.TriggerChecks = types.Int64Value(monitor.TriggerChecks)
	model.RecoveryChecks = types.Int64Value(monitor.RecoveryChecks)
	model.Enabled = types.BoolValue(monitor.Enabled)
	model.Health = types.StringValue(monitor.Health)
}

func (r *monitorResource) path(model monitorModel) string {
	return "/projects/" + model.ProjectID.ValueString() + "/monitors/" + model.ID.ValueString()
}

func (r *monitorResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	var plan monitorModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	var monitor client.Monitor
	if err := r.api.Do(ctx, "POST", "/projects/"+plan.ProjectID.ValueString()+"/monitors", r.body(plan), &monitor); err != nil {
		resp.Diagnostics.AddError("Couldn't create the monitor", err.Error())
		return
	}
	r.fill(monitor, &plan)
	resp.Diagnostics.Append(resp.State.Set(ctx, plan)...)
}

func (r *monitorResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	var state monitorModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	var monitor client.Monitor
	err := r.api.Do(ctx, "GET", r.path(state), nil, &monitor)
	if errors.Is(err, client.ErrNotFound) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		resp.Diagnostics.AddError("Couldn't read the monitor", err.Error())
		return
	}
	r.fill(monitor, &state)
	resp.Diagnostics.Append(resp.State.Set(ctx, state)...)
}

func (r *monitorResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	var plan, state monitorModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	plan.ID = state.ID
	var monitor client.Monitor
	if err := r.api.Do(ctx, "PUT", r.path(plan), r.body(plan), &monitor); err != nil {
		resp.Diagnostics.AddError("Couldn't change the monitor", err.Error())
		return
	}
	r.fill(monitor, &plan)
	resp.Diagnostics.Append(resp.State.Set(ctx, plan)...)
}

func (r *monitorResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	var state monitorModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	if err := r.api.Do(ctx, "DELETE", r.path(state), nil, nil); err != nil && !errors.Is(err, client.ErrNotFound) {
		resp.Diagnostics.AddError("Couldn't archive the monitor", err.Error())
	}
}

// ImportState takes "project_id/monitor_id".
func (r *monitorResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	project, monitor, found := strings.Cut(req.ID, "/")
	if !found || project == "" || monitor == "" {
		resp.Diagnostics.AddError("Unexpected import ID", "Import a monitor as project_id/monitor_id.")
		return
	}
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("project_id"), project)...)
	resp.Diagnostics.Append(resp.State.SetAttribute(ctx, path.Root("id"), monitor)...)
}
