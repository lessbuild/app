<?php

namespace App\Modules\Deployer\Http\Controllers;

use App\Core\Services\Auth\ProductAuthentication;
use App\Modules\Deployer\Actions\Project\ApprovePreviewSecretsAction;
use App\Modules\Deployer\Actions\Project\CreateProjectAction;
use App\Modules\Deployer\Actions\Project\DeleteProjectAction;
use App\Modules\Deployer\Actions\Project\QueuePreviewStackCleanupAction;
use App\Modules\Deployer\Actions\Project\UpdateProjectPreviewsAction;
use App\Modules\Deployer\Http\Requests\ApprovePreviewSecretsRequest;
use App\Modules\Deployer\Http\Requests\StoreProjectRequest;
use App\Modules\Deployer\Http\Requests\UpdateProjectPreviewsRequest;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\PreviewDeployment;
use App\Modules\Deployer\Models\PreviewStackCleanup;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\Repository;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Rules\Hostname;
use App\Modules\Deployer\Services\ApplicationTemplateCatalog;
use App\Modules\Deployer\Services\Core\DeployerResourceProjection;
use App\Modules\Deployer\Services\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return view('scenes.projects.show', [
            'project' => $project->load([
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
            ]),
            'servers' => $request->user()->workspaceServers()->orderBy('name')->get(),
            'websites' => $request->user()->workspaceWebsites()->orderBy('name')->get(),
            'canManage' => $project->organization->permits($request->user(), 'manage'),
            'canDeploy' => $project->organization->permits($request->user(), 'deploy'),
            'featureAccess' => collect(['workers', 'resources', 'previews', 'scaling', 'hibernation', 'monitoring'])
                ->mapWithKeys(fn (string $feature): array => [$feature => $entitlements->allows($project->organization, $feature)]),
        ]);
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
