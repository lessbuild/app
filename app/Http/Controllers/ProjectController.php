<?php

namespace App\Http\Controllers;

use App\Actions\Project\CreateProjectAction;
use App\Http\Requests\StoreProjectRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Rules\Hostname;
use App\Services\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProjectController extends Controller
{
    /**
     * Render current-workspace applications with environment counts in creation order.
     */
    public function index(Request $request): View
    {
        return view('scenes.projects.index', [
            'projects' => $request->user()->currentOrganization->projects()->withCount('environments')->latest()->get(),
        ]);
    }

    /**
     * Require workspace deployment permission and render the configured application templates.
     */
    public function create(Request $request): View
    {
        $this->authorize('create', Project::class);

        return view('scenes.projects.create', ['templates' => config('application-templates')]);
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

        return view('scenes.projects.show', [
            'project' => $project->load([
                'environments.server',
                'environments.website.repositories.provider',
                'environments.website.repositories.latestBuild',
                'environments.website.repositories.latestSuccessfulBuild',
                'environments.variables',
                'environments.processes',
                'environments.resources',
                'previews.website',
            ]),
            'servers' => $request->user()->workspaceServers()->orderBy('name')->get(),
            'websites' => $request->user()->workspaceWebsites()->orderBy('name')->get(),
            'canManage' => $project->organization->permits($request->user(), 'manage'),
            'canDeploy' => $project->organization->permits($request->user(), 'deploy'),
            'featureAccess' => collect(['workers', 'resources', 'previews', 'scaling', 'hibernation'])
                ->mapWithKeys(fn (string $feature): array => [$feature => $entitlements->allows($project->organization, $feature)]),
        ]);
    }

    /**
     * Authorize deletion of the bound application and redirect to the application list after removing its record.
     */
    public function destroy(Project $project): RedirectResponse
    {
        $this->authorize('delete', $project);
        $project->delete();

        return redirect()->route('projects.index')->with('success', __('Application deleted.'));
    }

    /**
     * Normalize and validate preview enablement, hostname, and expiry for an editable application, then save the settings.
     */
    public function updatePreviews(Request $request, Project $project, Entitlements $entitlements): RedirectResponse
    {
        $this->authorize('update', $project);
        $domain = preg_replace('#^https?://#i', '', trim((string) $request->input('preview_domain')));
        $request->merge([
            'preview_enabled' => $request->boolean('preview_enabled'),
            'preview_domain' => rtrim((string) $domain, '/'),
        ]);
        if ($request->boolean('preview_enabled')) {
            $entitlements->enforce($project->organization, 'previews');
        }
        $data = $request->validate([
            'preview_enabled' => ['required', 'boolean'],
            'preview_domain' => ['required_if:preview_enabled,true', 'nullable', 'string', 'max:200', new Hostname],
            'preview_ttl_hours' => ['required', 'integer', 'between:1,720'],
        ]);
        $project->update($data);

        return back()->with('success', __('Preview environment settings saved.'));
    }
}
