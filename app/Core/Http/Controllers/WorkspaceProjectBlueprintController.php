<?php

namespace App\Core\Http\Controllers;

use App\Core\Data\Blueprints\BlueprintPreview;
use App\Core\Exceptions\Blueprints\BlueprintBlocked;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectBlueprint;
use App\Core\Models\ProjectBlueprintRun;
use App\Core\Models\ProjectBlueprintVersion;
use App\Core\Models\Workspace;
use App\Core\Services\Blueprints\BlueprintMessages;
use App\Core\Services\Blueprints\PreviewProjectBlueprint;
use App\Core\Services\Blueprints\ProjectBlueprintProviderRegistry;
use App\Core\Services\Blueprints\RequestProjectBlueprint;
use App\Core\Services\Blueprints\RetryProjectBlueprint;
use App\Core\Services\Blueprints\SaveProjectBlueprintVersion;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

final class WorkspaceProjectBlueprintController
{
    public function __construct(private readonly WorkspaceProjectAccess $access) {}

    public function index(Request $request, Workspace $workspace, ProjectBlueprintProviderRegistry $providers): Response
    {
        $actor = $this->actor($request, $workspace);
        $membership = $this->access->activeMembership($actor, $workspace);
        $products = [];
        foreach ($providers->all() as $product => $provider) {
            if (config('platform.products.'.$product.'.enabled') && $this->access->hasProductAccess($membership, $product)) {
                $products[$product] = $provider->example();
            }
        }

        return $this->page('index', $actor, $workspace, [
            'blueprints' => ProjectBlueprint::query()->where('workspace_id', $workspace->getKey())->whereNull('archived_at')
                ->with(['versions' => fn ($query) => $query->limit(5)])->latest('updated_at')->paginate(20),
            'runs' => ProjectBlueprintRun::query()->where('workspace_id', $workspace->getKey())
                ->whereIn('project_id', $this->projectQuery($actor, $workspace)->select('id'))->latest('created_at')->limit(20)->get(),
            'example' => json_encode(['schema_version' => 1, 'environments' => [['key' => 'production', 'name' => 'Production', 'type' => 'production']], 'products' => $products], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'hasProviders' => $products !== [],
        ]);
    }

    public function store(Request $request, Workspace $workspace, SaveProjectBlueprintVersion $save): RedirectResponse
    {
        return $this->save($request, $workspace, $save);
    }

    public function storeVersion(Request $request, Workspace $workspace, ProjectBlueprint $blueprint, SaveProjectBlueprintVersion $save): RedirectResponse
    {
        abort_unless((string) $blueprint->workspace_id === (string) $workspace->getKey(), 404);

        return $this->save($request, $workspace, $save, $blueprint);
    }

    public function show(Request $request, Workspace $workspace, ProjectBlueprintVersion $blueprintVersion): Response
    {
        $actor = $this->actor($request, $workspace);
        $this->version($actor, $workspace, $blueprintVersion);

        return $this->versionPage($request, $actor, $workspace, $blueprintVersion);
    }

    public function preview(Request $request, Workspace $workspace, ProjectBlueprintVersion $blueprintVersion, PreviewProjectBlueprint $previews): Response|RedirectResponse
    {
        $actor = $this->actor($request, $workspace);
        $this->version($actor, $workspace, $blueprintVersion);
        $data = $request->validate(['project_id' => ['required', 'ulid'], 'environments' => ['required', 'array'], 'environments.*' => ['nullable', 'ulid']]);
        $project = Project::query()->where('workspace_id', $workspace->getKey())->findOrFail($data['project_id']);
        try {
            $preview = $previews->handle($actor, $workspace, $project, $blueprintVersion, $data['environments']);
        } catch (BlueprintBlocked $exception) {
            return back()->withErrors(['preview' => BlueprintMessages::reason($exception->reason)]);
        }
        $key = (string) Str::uuid();

        return $this->versionPage($request, $actor, $workspace, $blueprintVersion, $preview, $previews->token($preview, (string) $blueprintVersion->getKey(), $key), $key);
    }

    public function apply(Request $request, Workspace $workspace, ProjectBlueprintVersion $blueprintVersion, RequestProjectBlueprint $apply): RedirectResponse
    {
        $actor = $this->actor($request, $workspace);
        $this->version($actor, $workspace, $blueprintVersion);
        $data = $request->validate(['project_id' => ['required', 'ulid'], 'environments' => ['required', 'array'], 'environments.*' => ['nullable', 'ulid'],
            'preview_token' => ['required', 'string', 'max:12000'], 'idempotency_key' => ['required', 'uuid'], 'confirmed' => ['accepted']]);
        $project = Project::query()->where('workspace_id', $workspace->getKey())->findOrFail($data['project_id']);
        try {
            $run = $apply->handle($actor, $workspace, $project, $blueprintVersion, $data['environments'], $data['preview_token'], $data['idempotency_key']);
        } catch (BlueprintBlocked $exception) {
            return to_route('core.workspace.blueprints.show', [$workspace, $blueprintVersion, 'project_id' => $project->getKey()])
                ->withErrors(['preview' => BlueprintMessages::reason($exception->reason)]);
        }

        return to_route('core.workspace.blueprints.runs.show', [$workspace, $run]);
    }

    public function progress(Request $request, Workspace $workspace, ProjectBlueprintRun $blueprintRun): Response
    {
        $actor = $this->actor($request, $workspace);
        $project = $this->runProject($actor, $workspace, $blueprintRun);

        return $this->page('progress', $actor, $workspace, ['run' => $blueprintRun->load('steps'), 'project' => $project,
            'canRetry' => (string) $blueprintRun->requested_by_user_id === (string) $actor->getKey()]);
    }

    public function retry(Request $request, Workspace $workspace, ProjectBlueprintRun $blueprintRun, RetryProjectBlueprint $retry): RedirectResponse
    {
        $actor = $this->actor($request, $workspace);
        $this->runProject($actor, $workspace, $blueprintRun);
        try {
            $retry->handle($actor, $blueprintRun);
        } catch (BlueprintBlocked $exception) {
            return back()->withErrors(['blueprint' => BlueprintMessages::reason($exception->reason)]);
        }

        return to_route('core.workspace.blueprints.runs.show', [$workspace, $blueprintRun])->with('success', __('The saved blueprint application is ready to resume. Completed app steps are preserved.'));
    }

    private function save(Request $request, Workspace $workspace, SaveProjectBlueprintVersion $save, ?ProjectBlueprint $blueprint = null): RedirectResponse
    {
        $actor = $this->actor($request, $workspace);
        try {
            $data = Validator::make($request->only(['name', 'description', 'definition']), ['name' => ['required', 'string', 'max:120'],
                'description' => ['nullable', 'string', 'max:1000'], 'definition' => ['required', 'string', 'max:65536']])->validate();
            $definition = json_decode($data['definition'], true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($definition)) {
                throw new JsonException;
            }
            $version = $save->handle($actor, $workspace, $data['name'], $data['description'] ?? null, $definition, $blueprint);
        } catch (JsonException) {
            return back()->withErrors(['definition' => __('Enter a valid JSON blueprint object.')]);
        } catch (ValidationException $exception) {
            // Invalid definitions may contain secrets entered in error. Never flash them.
            return back()->withErrors($exception->errors());
        }

        return to_route('core.workspace.blueprints.show', [$workspace, $version])->with('success', __('Blueprint version saved. Review a preview before applying it to a project.'));
    }

    private function versionPage(Request $request, PlatformUser $actor, Workspace $workspace, ProjectBlueprintVersion $version, ?BlueprintPreview $preview = null, ?string $token = null, ?string $key = null): Response
    {
        $search = $request->query('q', '');
        abort_unless(is_string($search) && mb_strlen($search) <= 120, 422);
        $search = trim($search);
        $projects = $this->projectQuery($actor, $workspace)
            ->when($search !== '', fn (Builder $query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')->orderBy('id')->limit(201)->get();
        $truncated = $projects->count() > 200;
        $projects = $projects->take(200);
        $project = $projects->first();
        if ($request->filled('project_id')) {
            $projectId = $request->input('project_id');
            abort_unless(is_string($projectId) && Str::isUlid($projectId), 404);
            // An explicit authorized target remains reachable beyond the bounded picker.
            $project = $this->projectQuery($actor, $workspace)->findOrFail($projectId);
            if (! $projects->contains('id', $project->getKey())) {
                $projects->prepend($project);
            }
        }

        return $this->page('version', $actor, $workspace, ['version' => $version, 'blueprint' => $version->blueprint,
            'projects' => $projects, 'projectSearch' => $search, 'projectsTruncated' => $truncated, 'project' => $project,
            'environments' => $project?->environments()->where('status', 'active')->orderBy('name')->get() ?? collect(),
            'selections' => $request->input('environments', []), 'preview' => $preview, 'previewToken' => $token, 'idempotencyKey' => $key,
            'definitionJson' => json_encode($version->definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)]);
    }

    private function actor(Request $request, Workspace $workspace): PlatformUser
    {
        $actor = $request->user('platform');
        abort_unless($actor instanceof PlatformUser, 401);
        abort_unless($this->access->canManageWorkspace($actor, $workspace), 403);

        return $actor;
    }

    private function version(PlatformUser $actor, Workspace $workspace, ProjectBlueprintVersion $version): void
    {
        abort_unless((string) $version->blueprint?->workspace_id === (string) $workspace->getKey() && $version->blueprint->archived_at === null, 404);
        $membership = $this->access->activeMembership($actor, $workspace);
        foreach (array_keys($version->definition['products']) as $product) {
            abort_unless($membership !== null && $this->access->hasProductAccess($membership, $product), 403);
        }
    }

    private function projectQuery(PlatformUser $actor, Workspace $workspace): Builder
    {
        return Project::query()->where('workspace_id', $workspace->getKey())->where('status', 'active')->whereNull('archived_at')
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $actor->getKey())->where('status', 'active')->whereNull('revoked_at'));
    }

    private function runProject(PlatformUser $actor, Workspace $workspace, ProjectBlueprintRun $run): Project
    {
        abort_unless((string) $run->workspace_id === (string) $workspace->getKey(), 404);
        $project = Project::query()->where('workspace_id', $workspace->getKey())->findOrFail($run->project_id);
        abort_unless($this->access->canViewProject($actor, $project), 404);

        return $project;
    }

    private function page(string $view, PlatformUser $actor, Workspace $workspace, array $data): Response
    {
        return response()->view('core::blueprints.'.$view, [...$data, 'user' => $actor, 'workspace' => $workspace,
            'workspaces' => Workspace::query()->where('status', 'active')->whereNull('archived_at')
                ->whereHas('memberships', fn ($query) => $query->currentlyActive()->where('user_id', $actor->getKey()))->orderBy('name')->get()])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
