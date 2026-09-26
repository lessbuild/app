<?php

namespace App\Modules\Deployer\Http\Controllers;

use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectEnvironment as CoreProjectEnvironment;
use App\Core\Models\ProjectResource as CoreProjectResource;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Deployer\Actions\Project\ApprovePreviewSecretsAction;
use App\Modules\Deployer\Actions\Project\CreateProjectAction;
use App\Modules\Deployer\Actions\Project\DeleteProjectAction;
use App\Modules\Deployer\Actions\Project\InstallBlueprintRecipeSnapshotAction;
use App\Modules\Deployer\Actions\Project\QueuePreviewStackCleanupAction;
use App\Modules\Deployer\Actions\Project\UpdateProjectPreviewsAction;
use App\Modules\Deployer\Http\Requests\ApprovePreviewSecretsRequest;
use App\Modules\Deployer\Http\Requests\StoreProjectRequest;
use App\Modules\Deployer\Http\Requests\UpdateProjectPreviewsRequest;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\EnvironmentBlueprintRecipe;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\PreviewDeployment;
use App\Modules\Deployer\Models\PreviewStackCleanup;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\Repository;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Rules\Hostname;
use App\Modules\Deployer\Services\ApplicationTemplateCatalog;
use App\Modules\Deployer\Services\Core\DeployerProjectAccess;
use App\Modules\Deployer\Services\Core\DeployerResourceProjection;
use App\Modules\Deployer\Services\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProjectController extends Controller
{
    /**
     * Render current-workspace applications with environment counts in creation order.
     */
    public function index(Request $request, ApplicationTemplateCatalog $templates): View
    {
        return view('scenes.projects.index', [
            'projects' => $request->user()->workspaceProjects()->withCount('environments')->latest()->get(),
            'templates' => $templates->all(),
        ]);
    }

    /**
     * Require workspace deployment permission and render the configured application templates.
     */
    public function create(Request $request, ApplicationTemplateCatalog $templates): View
    {
        $this->authorize('create', Project::class);

        return view('scenes.projects.create', ['templates' => $templates->all()]);
    }

    /**
     * Validate an application name, description, and template, then create protected production defaults atomically.
     *
     * @return RedirectResponse The created application page with any entitled template processes configured.
     */
    public function store(StoreProjectRequest $request, CreateProjectAction $createProject): RedirectResponse
    {
        /** @var Organization $organization */
        $organization = $request->user()->currentOrganization;
        $project = $createProject->handle($organization, $request->user(), $request->validated());

        return redirect()->route('projects.show', $project)->with('success', __('Application created with a protected production environment.'));
    }

    /**
     * Authorize application visibility and render its environment placements, resources, previews, and available features.
     */
    public function show(Request $request, Project $project, Entitlements $entitlements): View
    {
        $this->authorize('view', $project);
        $actor = $request->user();
        $projection = app(DeployerResourceProjection::class);
        $usesCore = app(ProductAuthentication::class)->usesCoreAuthority('deployer');
        $environments = $projection->environments(Environment::query(), $actor)->select('environments.id');
        $websites = $projection->websites(Website::withTrashed(), $actor)->select('websites.id');
        $repositories = $projection->repositories(Repository::withTrashed(), $actor)->select('repositories.id');
        $servers = $projection->servers(Server::query(), $actor)->select('servers.id');
        $builds = $projection->builds(Build::query(), $actor)->select('builds.id');
        $visibleEnvironments = $projection->environments(Environment::query()->where('project_id', $project->getKey()), $actor)
            ->get(['environments.id', 'environments.type']);
        $hasSnapshotStore = $usesCore && Schema::connection('deployer')->hasTable('environment_blueprint_recipes');
        $snapshotBindings = $hasSnapshotStore
            ? $this->currentCanonicalEnvironmentBindings($project, $visibleEnvironments)
            : ['project_id' => null, 'environments' => []];
        $canonicalProjectId = $snapshotBindings['project_id'];
        $canonicalEnvironmentBindings = $snapshotBindings['environments'];

        $project->load([
            'environments' => fn ($query) => $projection->environments($query, $actor),
            'environments.server' => fn ($query) => $projection->servers($query, $actor),
            'environments.website' => fn ($query) => $projection->websites($query, $actor),
            'environments.website.repositories' => fn ($query) => $projection->repositories($query, $actor),
            'environments.website.repositories.provider',
            'environments.website.repositories.latestBuild' => fn ($query) => $projection->builds($query, $actor),
            'environments.website.repositories.latestSuccessfulBuild' => fn ($query) => $projection->builds($query, $actor),
            'environments.variables',
            'environments.processes',
            'environments.resources',
            ...($hasSnapshotStore ? ['environments.blueprintRecipeSnapshots' => function ($query) use ($actor, $project, $canonicalProjectId, $canonicalEnvironmentBindings): void {
                $query
                    ->select([
                        'id', 'environment_id', 'step_id', 'workspace_source_id', 'canonical_project_id',
                        'canonical_environment_id', 'environment_key', 'source_recipe_id', 'source_user_id',
                        'source_organization_id', 'source_gallery_recipe_id', 'source_name', 'source_description',
                        'source_is_published', 'source_updated_at', 'source_revision_at', 'source_published_at',
                        'source_gallery_revision_at', 'position', 'installed_recipe_id', 'install_attempted_at', 'created_at',
                    ])
                    ->where(function ($bindings) use ($canonicalProjectId, $canonicalEnvironmentBindings): void {
                        if ($canonicalProjectId === null || $canonicalEnvironmentBindings === []) {
                            $bindings->whereRaw('1 = 0');

                            return;
                        }
                        foreach ($canonicalEnvironmentBindings as $environmentId => $canonicalEnvironmentId) {
                            $bindings->orWhere(fn ($binding) => $binding
                                ->where('environment_id', $environmentId)
                                ->where('canonical_project_id', $canonicalProjectId)
                                ->where('canonical_environment_id', $canonicalEnvironmentId));
                        }
                    })
                    ->where(fn ($visibility) => $visibility
                        ->where('source_organization_id', $project->organization_id)
                        ->orWhere(fn ($personal) => $personal->whereNull('source_organization_id')
                            ->where('source_user_id', $actor->getKey())))
                    ->orderBy('position')->orderBy('id');
            }] : []),
            'previews' => fn ($query) => $query->when($usesCore, fn ($preview) => $preview
                ->where(fn ($source) => $source->whereNull('source_repository_id')->orWhereIn('source_repository_id', clone $repositories))
                ->where(fn ($source) => $source->whereNull('source_environment_id')->orWhereIn('source_environment_id', clone $environments))
                ->where(fn ($source) => $source->whereNull('environment_id')->orWhereIn('environment_id', clone $environments))
                ->where(fn ($source) => $source->whereNull('website_id')->orWhereIn('website_id', clone $websites))
                ->where(fn ($source) => $source->whereNull('repository_id')->orWhereIn('repository_id', clone $repositories))
                ->where(fn ($source) => $source->whereNull('initialization_build_id')->orWhereIn('initialization_build_id', clone $builds))),
            'previews.website' => fn ($query) => $projection->websites($query, $actor),
            'previews.sourceEnvironment' => fn ($query) => $projection->environments($query, $actor),
            'previews.sourceEnvironment.variables',
            'previews.stackCleanups' => fn ($query) => $query->when($usesCore, fn ($cleanup) => $cleanup
                ->where(fn ($source) => $source->whereNull('environment_id')->orWhereIn('environment_id', clone $environments))
                ->where(fn ($source) => $source->whereNull('website_id')->orWhereIn('website_id', clone $websites))
                ->where(fn ($source) => $source->whereNull('server_id')->orWhereIn('server_id', clone $servers))),
        ]);
        foreach ($project->environments as $environment) {
            if (! $hasSnapshotStore) {
                $environment->setRelation('blueprintRecipeSnapshots', collect());
            }
            $environment->setAttribute('prepared_recipe_snapshot_count', $environment->blueprintRecipeSnapshots->count());
        }
        $canManageProjectResources = $project->organization->permits($actor, 'manage');
        $canInstallBlueprintSnapshots = $project->environments
            ->filter(fn (Environment $environment): bool => $usesCore
                && $project->organization->permits($actor, 'deploy')
                && isset($canonicalEnvironmentBindings[(string) $environment->getKey()])
                && $canonicalProjectId !== null
                && app(DeployerProjectAccess::class)->canChangeEnvironment($actor, $environment)
                && (! $environment->is_protected && ! $environment->requires_deployment_approval || $canManageProjectResources))
            ->map(fn (Environment $environment): string => (string) $environment->getKey())
            ->all();

        return view('scenes.projects.show', [
            'project' => $project,
            'servers' => $request->user()->workspaceServers()->orderBy('name')->get(),
            'websites' => $request->user()->workspaceWebsites()->orderBy('name')->get(),
            'canManage' => $project->organization->permits($request->user(), 'manage'),
            'canDeploy' => $project->organization->permits($request->user(), 'deploy'),
            'canInstallBlueprintSnapshots' => $canInstallBlueprintSnapshots,
            'featureAccess' => collect(['workers', 'resources', 'previews', 'scaling', 'hibernation', 'monitoring'])
                ->mapWithKeys(fn (string $feature): array => [$feature => $entitlements->allows($project->organization, $feature)]),
        ]);
    }

    /** @return array{project_id: ?string, environments: array<string, string>} */
    private function currentCanonicalEnvironmentBindings(Project $project, Collection $environments): array
    {
        $identities = app(LegacyIdentityResolver::class);
        $workspaceId = $identities->canonicalIdForSource('deployer', 'organization', (string) $project->organization_id, 'workspace');
        if ($workspaceId === null) {
            return ['project_id' => null, 'environments' => []];
        }
        $workspaceSources = $identities->sourceIdsForCanonical('deployer', 'organization', $workspaceId, 'workspace');
        $workspace = CoreWorkspace::query()->whereKey($workspaceId)->where('status', 'active')->whereNull('archived_at')->first();
        if (count($workspaceSources) !== 1 || (string) $workspaceSources[0] !== (string) $project->organization_id || $workspace === null) {
            return ['project_id' => null, 'environments' => []];
        }

        $projectMappings = CoreProjectResource::query()->where('product', 'deployer')->where('resource_type', 'project')
            ->where('resource_id', (string) $project->getKey())->get();
        if ($projectMappings->count() !== 1 || $projectMappings->first()->status !== 'active') {
            return ['project_id' => null, 'environments' => []];
        }
        $projectMapping = $projectMappings->first();
        $canonicalProject = CoreProject::query()->whereKey($projectMapping->project_id)->where('workspace_id', $workspace->getKey())
            ->where('status', 'active')->whereNull('archived_at')->first();
        $canonicalProjectMappings = CoreProjectResource::query()->where('product', 'deployer')->where('resource_type', 'project')
            ->where('project_id', $projectMapping->project_id)->get();
        if ($canonicalProject === null || $canonicalProjectMappings->count() !== 1
            || (string) $canonicalProjectMappings->first()->getKey() !== (string) $projectMapping->getKey()) {
            return ['project_id' => null, 'environments' => []];
        }

        $environmentIds = $environments->map(fn (Environment $environment): string => (string) $environment->getKey())->all();
        if ($environmentIds === []) {
            return ['project_id' => (string) $canonicalProject->getKey(), 'environments' => []];
        }
        $mappingsByNativeId = CoreProjectResource::query()->where('product', 'deployer')->where('resource_type', 'environment')
            ->whereIn('resource_id', $environmentIds)->get()->groupBy(fn (CoreProjectResource $mapping): string => (string) $mapping->resource_id);
        $candidateCanonicalEnvironmentIds = $mappingsByNativeId->flatten(1)->pluck('environment_id')->filter()->unique()->values()->all();
        $canonicalEnvironments = CoreProjectEnvironment::query()->whereIn('id', $candidateCanonicalEnvironmentIds)
            ->where('project_id', $canonicalProject->getKey())->where('status', 'active')->get()->keyBy(fn (CoreProjectEnvironment $environment): string => (string) $environment->getKey());
        $mappingsByCanonicalEnvironmentId = CoreProjectResource::query()->where('product', 'deployer')
            ->where('resource_type', 'environment')
            ->whereIn('environment_id', $candidateCanonicalEnvironmentIds)->get()
            ->groupBy(fn (CoreProjectResource $mapping): string => (string) $mapping->environment_id);

        $bindings = [];
        foreach ($environments as $environment) {
            $nativeMappings = $mappingsByNativeId->get((string) $environment->getKey(), collect());
            if ($nativeMappings->count() !== 1 || $nativeMappings->first()->status !== 'active'
                || (string) $nativeMappings->first()->project_id !== (string) $canonicalProject->getKey()
                || $nativeMappings->first()->environment_id === null) {
                continue;
            }
            $mapping = $nativeMappings->first();
            $canonicalEnvironment = $canonicalEnvironments->get((string) $mapping->environment_id);
            $canonicalEnvironmentMappings = $mappingsByCanonicalEnvironmentId->get((string) $mapping->environment_id, collect());
            if ($canonicalEnvironment === null || $canonicalEnvironment->environment_type !== $environment->type
                || $canonicalEnvironmentMappings->count() !== 1 || $canonicalEnvironmentMappings->first()->status !== 'active'
                || (string) $canonicalEnvironmentMappings->first()->getKey() !== (string) $mapping->getKey()) {
                continue;
            }
            $bindings[(string) $environment->getKey()] = (string) $canonicalEnvironment->getKey();
        }

        return ['project_id' => (string) $canonicalProject->getKey(), 'environments' => $bindings];
    }

    /** Copy one currently authorized immutable blueprint recipe snapshot into the workspace recipe library. */
    public function installBlueprintRecipeSnapshot(
        Request $request,
        Project $project,
        Environment $environment,
        EnvironmentBlueprintRecipe $blueprintRecipeSnapshot,
        InstallBlueprintRecipeSnapshotAction $installSnapshot,
    ): RedirectResponse {
        $this->authorize('view', $project);
        $validated = $request->validate([
            'share_with_workspace' => ['sometimes', 'accepted'],
        ]);
        $recipe = $installSnapshot->handle(
            $request->user(),
            $project,
            $environment,
            $blueprintRecipeSnapshot,
            (bool) ($validated['share_with_workspace'] ?? false),
        );

        return redirect()->route('recipes.show', $recipe)
            ->with('success', __('Prepared recipe snapshot is in the workspace library. Review it before using it on a server.'));
    }

    /**
     * Authorize deletion of the bound application and redirect to the application list after removing its record.
     */
    public function destroy(Project $project, DeleteProjectAction $deleteProject): RedirectResponse
    {
        $this->authorize('delete', $project);
        $deleteProject->handle($project);

        return redirect()->route('projects.index')->with('success', __('Application deleted.'));
    }

    /**
     * Normalize and validate preview enablement, hostname, and expiry for an editable application, then save the settings.
     */
    public function updatePreviews(
        UpdateProjectPreviewsRequest $request,
        Project $project,
        UpdateProjectPreviewsAction $updateProjectPreviews,
    ): RedirectResponse {
        $updateProjectPreviews->handle($project, $request->validated());

        return back()->with('success', __('Preview environment settings saved.'));
    }

    /**
     * Authorize and record the manager's explicit, revision-bound preview secret scope.
     */
    public function approvePreviewSecrets(
        ApprovePreviewSecretsRequest $request,
        Project $project,
        PreviewDeployment $preview,
        ApprovePreviewSecretsAction $approvePreviewSecrets,
    ): RedirectResponse {
        try {
            $approval = $approvePreviewSecrets->handle($preview, $request->user(), $request->revision(), $request->secretKeys());
        } catch (ValidationException $exception) {
            return back()
                ->withErrors($exception->errors(), $exception->errorBag)
                ->withInput([]);
        }

        return $approval
            ? back()->with('success', __('Selected preview secrets were approved for this revision. The next verified update will apply them.'))
            : back()->with('info', __('This preview is closed, unavailable, or no longer belongs to your managed workspace.'));
    }

    /**
     * Authorize and requeue cleanup for a closed preview whose remote stack remains incomplete.
     */
    public function retryPreviewCleanup(
        Project $project,
        PreviewDeployment $preview,
        QueuePreviewStackCleanupAction $queuePreviewCleanup,
    ): RedirectResponse {
        $this->authorize('retryCleanup', $preview);
        $cleanup = $queuePreviewCleanup->handle($preview);

        if (! $cleanup) {
            return back()->with('info', __('This preview has no retryable stack cleanup.'));
        }

        return $cleanup->status === PreviewStackCleanup::STATUS_QUEUED
            ? back()->with('success', __('Preview stack cleanup queued.'))
            : back()->with('info', __('This preview stack cleanup is already running or completed.'));
    }
}
