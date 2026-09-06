<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Rules\Hostname;
use App\Services\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(Request $request): View
    {
        return view('scenes.projects.index', [
            'projects' => $request->user()->currentOrganization->projects()->withCount('environments')->latest()->get(),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->currentOrganization->permits($request->user(), 'deploy'), 403);

        return view('scenes.projects.create', ['templates' => config('application-templates')]);
    }

    public function store(Request $request, Entitlements $entitlements): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization->permits($request->user(), 'deploy'), 403);
        $request->merge(['preset' => $request->input('preset', 'laravel')]);
        $templates = config('application-templates', []);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'preset' => ['required', Rule::in(array_keys($templates))],
        ]);
        $template = $templates[$data['preset']];
        $slug = $this->uniqueSlug($organization->id, $data['name']);
        $project = DB::transaction(function () use ($organization, $request, $data, $template, $slug, $entitlements): Project {
            $project = $organization->projects()->create([
                ...$data,
                'slug' => $slug,
                'created_by' => $request->user()->id,
            ]);
            $environment = $project->environments()->create([
                'name' => 'Production',
                'slug' => 'production',
                'type' => 'production',
                'branch' => 'main',
                'runtime_type' => $template['runtime_type'],
                'build_command' => $template['build_command'],
                'start_command' => $template['start_command'],
                'container_port' => $template['container_port'],
                'dockerfile_path' => $template['dockerfile_path'],
                'is_protected' => true,
                'requires_deployment_approval' => true,
            ]);
            if ($entitlements->allows($organization, 'workers')) {
                foreach ($template['processes'] as $process) {
                    $environment->processes()->create([
                        ...$process, 'replicas' => 1, 'restart_policy' => 'always', 'restart_delay_seconds' => 5, 'is_enabled' => true,
                    ]);
                }
            }

            return $project;
        });

        return redirect()->route('projects.show', $project)->with('success', __('Application created with a protected production environment.'));
    }

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

    public function destroy(Project $project): RedirectResponse
    {
        $this->authorize('delete', $project);
        $project->delete();

        return redirect()->route('projects.index')->with('success', __('Application deleted.'));
    }

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

    private function uniqueSlug(int $organizationId, string $name): string
    {
        $base = Str::slug($name) ?: 'application';
        $slug = $base;
        $suffix = 2;
        while (Project::query()->where('organization_id', $organizationId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
