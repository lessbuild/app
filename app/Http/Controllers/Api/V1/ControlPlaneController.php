<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Environment\QueueEnvironmentRuntimeStateAction;
use App\Actions\Environment\ReplaceEnvironmentVariablesAction;
use App\Actions\Environment\UpdateEnvironmentScalingAction;
use App\Actions\Repository\PromoteBuildAction;
use App\Actions\Repository\RollbackBuildAction;
use App\Data\BuildPromotionResult;
use App\Data\BuildRedeploymentResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ApplyConfigurationRequest;
use App\Http\Requests\Api\V1\ApplyWorkflowRequest;
use App\Http\Requests\Api\V1\CancelConfigurationRequest;
use App\Http\Requests\Api\V1\ConfigurationInputRequest;
use App\Http\Requests\Api\V1\ReplaceEnvironmentVariablesRequest;
use App\Http\Requests\Api\V1\RetryConfigurationRequest;
use App\Http\Requests\Api\V1\RuntimeEnvironmentRequest;
use App\Http\Requests\Api\V1\ScaleEnvironmentRequest;
use App\Models\Build;
use App\Models\ConfigurationApplication;
use App\Models\ConfigurationOperation;
use App\Models\ConfigurationReview;
use App\Models\Environment;
use App\Models\Project;
use App\Services\ApplicationConfigurationCancellation;
use App\Services\ApplicationConfigurationPlanner;
use App\Services\ApplicationConfigurationReconciler;
use App\Services\ApplicationConfigurationResults;
use App\Services\ApplicationConfigurationRetries;
use App\Services\ApplicationConfigurationReviews;
use App\Services\ControlPlaneAccess;
use App\Services\DeploymentLauncher;
use App\Services\WorkflowConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ControlPlaneController extends Controller
{
    /**
     * Use subscription entitlements to guard API access and paid runtime features.
     */
    public function __construct(
        private readonly ControlPlaneAccess $controlPlaneAccess,
        private readonly ApplicationConfigurationResults $configurationResults,
    ) {}

    /**
     * Require read ability and return the authenticated user plus their current workspace and billing plan.
     */
    public function me(Request $request): JsonResponse
    {
        $this->api($request, 'read');
        $organization = $request->user()->currentOrganization;

        return response()->json(['data' => ['id' => $request->user()->id, 'name' => $request->user()->name, 'email' => $request->user()->email,
            'organization' => ['id' => $organization->id, 'name' => $organization->name, 'plan' => $organization->owner->billingPlan()]]]);
    }

    /**
     * Require read ability and return current-workspace applications with their environment summaries.
     */
    public function projects(Request $request): JsonResponse
    {
        $this->api($request, 'read');
        $projects = $request->user()->currentOrganization->projects()->with('environments:id,project_id,name,slug,type,branch,desired_replicas,hibernated_at')->get();

        return response()->json(['data' => $projects->map(fn (Project $project) => $this->projectData($project))]);
    }

    /**
     * Require read ability and project visibility, then return its application and environment summary.
     */
    public function project(Request $request, Project $project): JsonResponse
    {
        $this->api($request, 'read');
        $this->authorize('view', $project);

        return response()->json(['data' => $this->projectData($project->load('environments'))]);
    }

    /**
     * Require read ability and return at most 100 recent deployments belonging to the current workspace.
     */
    public function deployments(Request $request): JsonResponse
    {
        $this->api($request, 'read');
        $builds = Build::query()->whereHas('repository', fn ($query) => $query->where('organization_id', $request->user()->current_organization_id))
            ->latest()->limit(100)->get();

        return response()->json(['data' => $builds->map(fn (Build $build) => $this->buildData($build))]);
    }

    /**
     * Require read ability and deployment visibility, then return the bound build's public API summary.
     */
    public function deployment(Request $request, Build $build): JsonResponse
    {
        $this->api($request, 'read');
        $this->authorize('view', $build);

        return response()->json(['data' => $this->buildData($build)]);
    }

    /**
     * Require read ability and deployment visibility, then return uncached log text or an empty string.
     */
    public function deploymentLog(Request $request, Build $build): JsonResponse
    {
        $this->api($request, 'read');
        $this->authorize('view', $build);
        $log = $build->logs()->where('type', Build::DEPLOYMENT_LOG_TYPE)->first();

        return response()->json(['data' => ['deployment_id' => $build->id, 'status' => $build->status, 'log' => $log?->log ?? '']])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Require deploy ability and rollback authorization before requesting the bound release.
     *
     * @return JsonResponse HTTP 202 with the queued deployment, or HTTP 409 with the blocking status.
     */
    public function rollback(Request $request, Build $build, RollbackBuildAction $rollback): JsonResponse
    {
        $this->api($request, 'deploy');
        $this->authorize('rollback', $build);
        $result = $rollback->handle($build, $request->user());
        $code = $result->status === BuildRedeploymentResult::QUEUED ? 202 : 409;

        return response()->json(['data' => ['status' => $result->status, 'deployment' => $result->build ? $this->buildData($result->build) : null]], $code);
    }

    /**
     * Validate a current-workspace target environment and optional note for an authorized source release.
     *
     * @return JsonResponse HTTP 202 when promotion is queued, or HTTP 409 with its rejection status.
     */
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

    /**
     * Require deploy ability and an editable environment, then launch its connected repository.
     *
     * @return JsonResponse HTTP 202 with the deployment; missing repositories yield 422 and unavailable environments 409.
     */
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

    /**
     * Require management ability and scaling entitlement, validate replica bounds, and queue the environment update.
     *
     * @return JsonResponse HTTP 202 with the desired replica count and queued status.
     */
    public function scale(ScaleEnvironmentRequest $request, Environment $environment, UpdateEnvironmentScalingAction $updateScaling): JsonResponse
    {
        $data = $request->validated();
        $updateScaling->handle($environment, ['desired_replicas' => $data['replicas']]);

        return response()->json(['data' => ['desired_replicas' => $data['replicas'], 'status' => 'queued']], 202);
    }

    /**
     * Validate running or hibernated state for an editable environment and enforce hibernation entitlement when needed.
     *
     * @return JsonResponse HTTP 202 with the requested state after its runtime job is queued.
     */
    public function runtime(RuntimeEnvironmentRequest $request, Environment $environment, QueueEnvironmentRuntimeStateAction $queueRuntime): JsonResponse
    {
        $data = $request->validated();
        $queueRuntime->handle($environment, $data['state']);

        return response()->json(['data' => ['status' => 'queued', 'state' => $data['state']]], 202);
    }

    /**
     * Require management ability, validate workflow text for an editable project, and return its atomic application result.
     */
    public function workflow(ApplyWorkflowRequest $request, Project $project, WorkflowConfiguration $workflow): JsonResponse
    {
        $workflow->apply($project, $request->workflow(), $request->user()->id);

        return response()->json(['data' => ['status' => 'applied']]);
    }

    /**
     * Require management ability and validate a configuration document plus placement, secret, and repository bindings.
     *
     * @return JsonResponse The proposed project changes without applying them.
     */
    public function configurationPlan(ConfigurationInputRequest $request, Project $project, ApplicationConfigurationPlanner $planner): JsonResponse
    {
        $this->authorize('manageConfiguration', $project);

        return response()->json(['data' => $planner->plan($project, $request->user(), $request->document(), $request->bindings())]);
    }

    /**
     * Validate configuration inputs for an editable project and persist their immutable review.
     *
     * @return JsonResponse HTTP 201 with the saved review identity, plan, and expiration.
     */
    public function configurationReview(ConfigurationInputRequest $request, Project $project, ApplicationConfigurationReviews $reviews): JsonResponse
    {
        $this->authorize('manageConfiguration', $project);
        $review = $reviews->create($project, $request->user(), $request->document(), $request->bindings());

        return response()->json(['data' => ['id' => $review->id, 'plan' => $review->summary, 'expires_at' => $review->expires_at->toIso8601String()]], 201);
    }

    /**
     * Apply a route-bound review belonging to the authorized project; reject replacement request inputs.
     *
     * @return JsonResponse The application receipt including current remote-operation outcomes.
     */
    public function configurationApply(ApplyConfigurationRequest $request, Project $project, ConfigurationReview $review, ApplicationConfigurationReconciler $reconciler): JsonResponse
    {
        $this->authorize('manageConfiguration', $project);
        abort_unless((int) $review->project_id === (int) $project->id, 404);
        $this->authorize('apply', $review);
        $application = $reconciler->apply($review, $request->user());

        return response()->json(['data' => $this->configurationApplicationData($application)]);
    }

    /**
     * Require management access and project ownership of the receipt, then return refreshed application outcomes.
     */
    public function configurationApplication(Project $project, ConfigurationApplication $application): JsonResponse
    {
        $this->authorize('viewConfiguration', $project);
        abort_unless((int) $application->review->project_id === (int) $project->id, 404);
        $this->authorize('view', $application);

        return response()->json(['data' => $this->configurationApplicationData($application)]);
    }

    /**
     * Require a project-owned application and related operation, with no replacement request inputs.
     *
     * @return JsonResponse The refreshed receipt after cancellation is requested.
     */
    public function configurationCancel(CancelConfigurationRequest $request, Project $project, ConfigurationApplication $application, ConfigurationOperation $operation, ApplicationConfigurationCancellation $cancellation): JsonResponse
    {
        $this->authorize('viewConfiguration', $project);
        abort_unless((int) $application->review->project_id === (int) $project->id, 404);
        abort_unless($application->relatedOperations()->whereKey($operation->id)->exists(), 404);
        $this->authorize('cancel', $application);
        $cancellation->cancel($operation, $request->user());

        return response()->json(['data' => $this->configurationApplicationData($application)]);
    }

    /**
     * Require a project-owned application and related failed operation, with no replacement request inputs.
     *
     * @return JsonResponse The refreshed receipt and the new retry operation identity.
     */
    public function configurationRetry(RetryConfigurationRequest $request, Project $project, ConfigurationApplication $application, ConfigurationOperation $operation, ApplicationConfigurationRetries $retries): JsonResponse
    {
        $this->authorize('viewConfiguration', $project);
        abort_unless((int) $application->review->project_id === (int) $project->id, 404);
        abort_unless($application->relatedOperations()->whereKey($operation->id)->exists(), 404);
        $this->authorize('retry', $application);
        $retry = $retries->retry($operation, $request->user());

        return response()->json(['data' => $this->configurationApplicationData($application), 'retry_operation_id' => $retry->id]);
    }

    /**
     * Refresh remote outcomes and serialize the receipt without configuration secrets.
     *
     * @return array{id: int, review_id: int, status: string, locally_applied_at: ?string, operations: list<array<string, mixed>>}
     */
    private function configurationApplicationData(ConfigurationApplication $application): array
    {
        $application = $this->configurationResults->refresh($application);

        return ['id' => $application->id, 'review_id' => $application->configuration_review_id,
            'status' => $application->status, 'locally_applied_at' => $application->locally_applied_at?->toIso8601String(),
            'operations' => $application->relatedOperations()->orderBy('id')->get()->map(fn ($operation) => $operation->only([
                'id', 'environment_slug', 'kind', 'status', 'build_id', 'attempts', 'failure_code', 'completed_at', 'retry_of_operation_id', 'retry_sequence',
            ]))->all()];
    }

    /**
     * Require management ability and replace an editable environment's variables from KEY=value lines atomically.
     *
     * @return JsonResponse Applied status and variable count; malformed lines fail validation and values remain secret.
     */
    public function variables(ReplaceEnvironmentVariablesRequest $request, Environment $environment, ReplaceEnvironmentVariablesAction $replaceVariables): JsonResponse
    {
        $count = $replaceVariables->handle($environment, $request->user(), $request->contents());

        return response()->json(['data' => ['status' => 'applied', 'count' => $count]]);
    }

    /**
     * Require the API entitlement, the workspace network policy, and the requested token ability; abort with 403 on denial.
     */
    private function api(Request $request, string $ability): void
    {
        $this->controlPlaneAccess->enforce($request, $ability);
    }

    /**
     * Serialize an application and its environment runtime summaries for API consumers.
     *
     * @return array{id: int, name: string, slug: string, preset: string, environments: Collection<int, array{id: int, name: string, slug: string, type: string, branch: ?string, desired_replicas: int, state: string}>}
     */
    private function projectData(Project $project): array
    {
        return ['id' => $project->id, 'name' => $project->name, 'slug' => $project->slug, 'preset' => $project->preset,
            'environments' => $project->environments->map(fn (Environment $environment) => [
                'id' => $environment->id, 'name' => $environment->name, 'slug' => $environment->slug, 'type' => $environment->type,
                'branch' => $environment->branch, 'desired_replicas' => $environment->desired_replicas,
                'state' => $environment->hibernated_at ? 'hibernated' : 'running',
            ])->values()];
    }

    /**
     * Serialize deployment identity, provenance, revision, and timestamps without logs or secrets.
     *
     * @return array{id: int, repository_id: int, environment_id: ?int, status: string, trigger: string, revision: ?string, promoted_from_build_id: ?int, created_at: ?string, finished_at: ?string}
     */
    private function buildData(Build $build): array
    {
        return ['id' => $build->id, 'repository_id' => $build->repository_id, 'environment_id' => $build->environment_id,
            'status' => $build->status, 'trigger' => $build->trigger_source, 'revision' => $build->revision,
            'promoted_from_build_id' => $build->promoted_from_build_id,
            'created_at' => $build->created_at?->toIso8601String(), 'finished_at' => $build->finished_at?->toIso8601String()];
    }
}
