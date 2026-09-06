<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Repository\PromoteBuildAction;
use App\Actions\Repository\RollbackBuildAction;
use App\Data\BuildPromotionResult;
use App\Data\BuildRedeploymentResult;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnforceOrganizationSecurity;
use App\Jobs\ApplyEnvironmentRuntimeStateJob;
use App\Models\Build;
use App\Models\Environment;
use App\Models\Project;
use App\Services\DeploymentLauncher;
use App\Services\Entitlements;
use App\Services\WorkflowConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ControlPlaneController extends Controller
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function me(Request $request): JsonResponse
    {
        $this->api($request, 'read');
        $organization = $request->user()->currentOrganization;

        return response()->json(['data' => ['id' => $request->user()->id, 'name' => $request->user()->name, 'email' => $request->user()->email,
            'organization' => ['id' => $organization->id, 'name' => $organization->name, 'plan' => $organization->owner->billingPlan()]]]);
    }

    public function projects(Request $request): JsonResponse
    {
        $this->api($request, 'read');
        $projects = $request->user()->currentOrganization->projects()->with('environments:id,project_id,name,slug,type,branch,desired_replicas,hibernated_at')->get();

        return response()->json(['data' => $projects->map(fn (Project $project) => $this->projectData($project))]);
    }

    public function project(Request $request, Project $project): JsonResponse
    {
        $this->api($request, 'read');
        $this->authorize('view', $project);

        return response()->json(['data' => $this->projectData($project->load('environments'))]);
    }

    public function deployments(Request $request): JsonResponse
    {
        $this->api($request, 'read');
        $builds = Build::query()->whereHas('repository', fn ($query) => $query->where('organization_id', $request->user()->current_organization_id))
            ->latest()->limit(100)->get();

        return response()->json(['data' => $builds->map(fn (Build $build) => $this->buildData($build))]);
    }

    public function deployment(Request $request, Build $build): JsonResponse
    {
        $this->api($request, 'read');
        $this->authorize('view', $build);

        return response()->json(['data' => $this->buildData($build)]);
    }

    public function deploymentLog(Request $request, Build $build): JsonResponse
    {
        $this->api($request, 'read');
        $this->authorize('view', $build);
        $log = $build->logs()->where('type', Build::DEPLOYMENT_LOG_TYPE)->first();

        return response()->json(['data' => ['deployment_id' => $build->id, 'status' => $build->status, 'log' => $log?->log ?? '']])
            ->header('Cache-Control', 'no-store, private');
    }

    public function rollback(Request $request, Build $build, RollbackBuildAction $rollback): JsonResponse
    {
        $this->api($request, 'deploy');
        $this->authorize('rollback', $build);
        $result = $rollback->handle($build, $request->user());
        $code = $result->status === BuildRedeploymentResult::QUEUED ? 202 : 409;

        return response()->json(['data' => ['status' => $result->status, 'deployment' => $result->build ? $this->buildData($result->build) : null]], $code);
    }

    public function promote(Request $request, Build $build, PromoteBuildAction $promote): JsonResponse
    {
        $this->api($request, 'deploy');
        $this->authorize('view', $build);
        $data = $request->validate(['target_environment_id' => ['required', 'integer'], 'promotion_note' => ['nullable', 'string', 'max:2000']]);
        $target = Environment::query()->whereKey($data['target_environment_id'])
            ->whereHas('project', fn ($query) => $query->where('organization_id', $request->user()->current_organization_id))
            ->firstOrFail();
        $result = $promote->handle($build, $target, $request->user(), $data['promotion_note'] ?? null);

        return response()->json([
            'data' => ['status' => $result->status, 'deployment' => $result->build ? $this->buildData($result->build) : null],
        ], $result->status === BuildPromotionResult::QUEUED ? 202 : 409);
    }

    public function deploy(Request $request, Environment $environment, DeploymentLauncher $launcher): JsonResponse
    {
        $this->api($request, 'deploy');
        $this->authorize('update', $environment);
        $repository = $environment->website?->repositories()->where('branch', $environment->branch)->first()
            ?? $environment->website?->repositories()->first();
        abort_unless($repository, 422, 'This environment has no repository.');
        $build = $launcher->launch($repository, $request->user(), Build::TRIGGER_API);
        abort_unless($build, 409, 'The environment is unavailable or already deploying.');

        return response()->json(['data' => $this->buildData($build)], 202);
    }

    public function scale(Request $request, Environment $environment): JsonResponse
    {
        $this->api($request, 'manage');
        $this->authorize('update', $environment);
        $this->entitlements->enforce($environment->project->organization, 'scaling');
        $data = $request->validate(['replicas' => ['required', 'integer', 'min:1', 'gte:'.$environment->minimum_replicas, 'lte:'.$environment->maximum_replicas]]);
        $environment->update(['desired_replicas' => $data['replicas'], 'hibernated_at' => null]);
        ApplyEnvironmentRuntimeStateJob::dispatch($environment->id, false);

        return response()->json(['data' => ['desired_replicas' => $data['replicas'], 'status' => 'queued']], 202);
    }

    public function runtime(Request $request, Environment $environment): JsonResponse
    {
        $this->api($request, 'manage');
        $this->authorize('update', $environment);
        $data = $request->validate(['state' => ['required', 'in:running,hibernated']]);
        if ($data['state'] === 'hibernated') {
            $this->entitlements->enforce($environment->project->organization, 'hibernation');
        }
        ApplyEnvironmentRuntimeStateJob::dispatch($environment->id, $data['state'] === 'hibernated');

        return response()->json(['data' => ['status' => 'queued', 'state' => $data['state']]], 202);
    }

    public function workflow(Request $request, Project $project, WorkflowConfiguration $workflow): JsonResponse
    {
        $this->api($request, 'manage');
        $this->authorize('update', $project);
        $data = $request->validate(['workflow' => ['required', 'string', 'max:50000']]);
        $workflow->apply($project, $data['workflow'], $request->user()->id);

        return response()->json(['data' => ['status' => 'applied']]);
    }

    public function variables(Request $request, Environment $environment): JsonResponse
    {
        $this->api($request, 'manage');
        $this->authorize('update', $environment);
        $data = $request->validate(['variables' => ['required', 'string', 'max:50000']]);
        $variables = [];
        foreach (preg_split('/\R/', $data['variables']) ?: [] as $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (! preg_match('/\A([A-Z_][A-Z0-9_]*)=(.*)\z/', $line, $matches)) {
                throw ValidationException::withMessages(['variables' => 'Each variable must use KEY=value on its own line.']);
            }
            $variables[$matches[1]] = $matches[2];
        }
        DB::transaction(function () use ($environment, $variables, $request): void {
            $environment->variables()->whereNotIn('key', array_keys($variables))->delete();
            foreach ($variables as $key => $value) {
                $variable = $environment->variables()->where('key', $key)->lockForUpdate()->first();
                $version = ($variable?->current_version ?? 0) + 1;
                $attributes = ['value' => $value, 'scope' => 'all', 'is_secret' => true, 'current_version' => $version, 'updated_by' => $request->user()->id, 'rotated_at' => $variable ? now() : null];
                if ($variable) {
                    $variable->update($attributes);
                } else {
                    $variable = $environment->variables()->create(['key' => $key, ...$attributes]);
                }
                $variable->versions()->create(['created_by' => $request->user()->id, 'version' => $version, 'value' => $value]);
            }
        });

        return response()->json(['data' => ['status' => 'applied', 'count' => count($variables)]]);
    }

    private function api(Request $request, string $ability): void
    {
        $this->entitlements->enforce($request->user(), 'api');
        $ranges = $request->user()->currentOrganization?->allowed_ip_ranges ?? [];
        abort_if($ranges !== [] && ! collect($ranges)->contains(fn (string $range): bool => app(EnforceOrganizationSecurity::class)->contains($range, (string) $request->ip())), 403, 'This network is not allowed by the workspace security policy.');
        abort_unless($request->user()->tokenCan($ability), 403, "Token lacks the {$ability} ability.");
    }

    private function projectData(Project $project): array
    {
        return ['id' => $project->id, 'name' => $project->name, 'slug' => $project->slug, 'preset' => $project->preset,
            'environments' => $project->environments->map(fn (Environment $environment) => [
                'id' => $environment->id, 'name' => $environment->name, 'slug' => $environment->slug, 'type' => $environment->type,
                'branch' => $environment->branch, 'desired_replicas' => $environment->desired_replicas,
                'state' => $environment->hibernated_at ? 'hibernated' : 'running',
            ])->values()];
    }

    private function buildData(Build $build): array
    {
        return ['id' => $build->id, 'repository_id' => $build->repository_id, 'environment_id' => $build->environment_id,
            'status' => $build->status, 'trigger' => $build->trigger_source, 'revision' => $build->revision,
            'promoted_from_build_id' => $build->promoted_from_build_id,
            'created_at' => $build->created_at?->toIso8601String(), 'finished_at' => $build->finished_at?->toIso8601String()];
    }
}
