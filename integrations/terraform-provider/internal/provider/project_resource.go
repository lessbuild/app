package provider

import (
	"context"
	"errors"

	"github.com/hashicorp/terraform-plugin-framework/path"
	"github.com/hashicorp/terraform-plugin-framework/resource"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/planmodifier"
	"github.com/hashicorp/terraform-plugin-framework/resource/schema/stringplanmodifier"
	"github.com/hashicorp/terraform-plugin-framework/types"
	"github.com/lessbuild/terraform-provider-buildpusher/internal/client"
)

var (
	_ resource.Resource                = (*projectResource)(nil)
	_ resource.ResourceWithImportState = (*projectResource)(nil)
)

// NewProjectResource returns the buildpusher_project resource.
func NewProjectResource() resource.Resource { return &projectResource{} }

type projectResource struct{ api *client.Client }

type projectModel struct {
	ID           types.String `tfsdk:"id"`
	Name         types.String `tfsdk:"name"`
	Description  types.String `tfsdk:"description"`
	Services     types.Set    `tfsdk:"services"`
	Slug         types.String `tfsdk:"slug"`
	Environments types.Map    `tfsdk:"environments"`
}

func (r *projectResource) Metadata(_ context.Context, req resource.MetadataRequest, resp *resource.MetadataResponse) {
	resp.TypeName = req.ProviderTypeName + "_project"
}

func (r *projectResource) Schema(_ context.Context, _ resource.SchemaRequest, resp *resource.SchemaResponse) {
	resp.Schema = schema.Schema{
		Description: "A project: one app or site, with the services it uses and its environments (production to start with).",
		Attributes: map[string]schema.Attribute{
			"id":          schema.StringAttribute{Computed: true, PlanModifiers: []planmodifier.String{stringplanmodifier.UseStateForUnknown()}},
			"name":        schema.StringAttribute{Required: true},
			"description": schema.StringAttribute{Optional: true},
			"services": schema.SetAttribute{
				ElementType: types.StringType, Optional: true, Computed: true,
				Description: "Services to turn on: deploy, infrastructure, monitoring, security and analytics.",
			},
			"slug":         schema.StringAttribute{Computed: true, PlanModifiers: []planmodifier.String{stringplanmodifier.UseStateForUnknown()}},
			"environments": schema.MapAttribute{ElementType: types.StringType, Computed: true, Description: "Environment IDs by slug, such as environments[\"production\"]."},
		},
	}
}

func (r *projectResource) Configure(_ context.Context, req resource.ConfigureRequest, resp *resource.ConfigureResponse) {
	r.api = configureClient(req.ProviderData, resp.Diagnostics.AddError)
}

func (r *projectResource) body(ctx context.Context, plan projectModel) (map[string]any, error) {
	body := map[string]any{"name": plan.Name.ValueString(), "description": stringPointer(plan.Description)}
	if !plan.Services.IsNull() && !plan.Services.IsUnknown() {
		services := []string{}
		if diags := plan.Services.ElementsAs(ctx, &services, false); diags.HasError() {
			return nil, errors.New("services must be a set of strings")
		}
		body["services"] = services
	}
	return body, nil
}

func (r *projectResource) fill(ctx context.Context, project client.Project, model *projectModel) {
	model.ID = types.StringValue(project.ID)
	model.Name = types.StringValue(project.Name)
	model.Description = optionalString(project.Description)
	model.Slug = types.StringValue(project.Slug)
	services, _ := types.SetValueFrom(ctx, types.StringType, project.Services)
	model.Services = services
	environments := map[string]string{}
	for _, environment := range project.Environments {
		environments[environment.Slug] = environment.ID
	}
	model.Environments, _ = types.MapValueFrom(ctx, types.StringType, environments)
}

func (r *projectResource) Create(ctx context.Context, req resource.CreateRequest, resp *resource.CreateResponse) {
	var plan projectModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	if resp.Diagnostics.HasError() {
		return
	}
	body, err := r.body(ctx, plan)
	if err != nil {
		resp.Diagnostics.AddError("Invalid services", err.Error())
		return
	}
	var project client.Project
	if err := r.api.Do(ctx, "POST", "/projects", body, &project); err != nil {
		resp.Diagnostics.AddError("Couldn't create the project", err.Error())
		return
	}
	r.fill(ctx, project, &plan)
	resp.Diagnostics.Append(resp.State.Set(ctx, plan)...)
}

func (r *projectResource) Read(ctx context.Context, req resource.ReadRequest, resp *resource.ReadResponse) {
	var state projectModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	var project client.Project
	err := r.api.Do(ctx, "GET", "/projects/"+state.ID.ValueString(), nil, &project)
	if errors.Is(err, client.ErrNotFound) {
		resp.State.RemoveResource(ctx)
		return
	}
	if err != nil {
		resp.Diagnostics.AddError("Couldn't read the project", err.Error())
		return
	}
	r.fill(ctx, project, &state)
	resp.Diagnostics.Append(resp.State.Set(ctx, state)...)
}

func (r *projectResource) Update(ctx context.Context, req resource.UpdateRequest, resp *resource.UpdateResponse) {
	var plan, state projectModel
	resp.Diagnostics.Append(req.Plan.Get(ctx, &plan)...)
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	body, err := r.body(ctx, plan)
	if err != nil {
		resp.Diagnostics.AddError("Invalid services", err.Error())
		return
	}
	var project client.Project
	if err := r.api.Do(ctx, "PUT", "/projects/"+state.ID.ValueString(), body, &project); err != nil {
		resp.Diagnostics.AddError("Couldn't change the project", err.Error())
		return
	}
	r.fill(ctx, project, &plan)
	resp.Diagnostics.Append(resp.State.Set(ctx, plan)...)
}

func (r *projectResource) Delete(ctx context.Context, req resource.DeleteRequest, resp *resource.DeleteResponse) {
	var state projectModel
	resp.Diagnostics.Append(req.State.Get(ctx, &state)...)
	if resp.Diagnostics.HasError() {
		return
	}
	if err := r.api.Do(ctx, "DELETE", "/projects/"+state.ID.ValueString(), nil, nil); err != nil && !errors.Is(err, client.ErrNotFound) {
		resp.Diagnostics.AddError("Couldn't delete the project", err.Error())
	}
}

func (r *projectResource) ImportState(ctx context.Context, req resource.ImportStateRequest, resp *resource.ImportStateResponse) {
	resource.ImportStatePassthroughID(ctx, path.Root("id"), req, resp)
}
