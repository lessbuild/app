<?php

declare(strict_types=1);

namespace App\Queries\Deploy;

use App\Actions\Deploy\UpdateEnvironmentHibernation;
use App\Data\Deploy\ProductionIssue;
use App\Enums\AlertDestinationType;
use App\Enums\ServerType;
use App\Models\AlertDestination;
use App\Models\DeploymentSchedule;
use App\Models\Environment;
use App\Models\EnvironmentDeployNotification;
use App\Models\EnvironmentFreeze;
use App\Models\EnvironmentProcess;
use App\Models\EnvironmentRecipe;
use App\Models\EnvironmentResource;
use App\Models\EnvironmentVariable;
use App\Models\PendingVariableChange;
use App\Models\Recipe;
use App\Models\Repository;
use App\Models\ScalingSchedule;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskRun;
use App\Models\SecretSync;
use App\Models\Server;
use App\Models\StorageBucket;
use App\Models\User;
use App\Models\Website;
use App\Services\Deploy\BuildServers;
use App\Services\Deploy\GitHubActionsBuilds;
use App\Services\Deploy\GitHubWorkflowPullRequests;
use App\Services\Deploy\ProductionCheck;

/** Reads everything an environment's deploy page shows, tab by tab. */
final class DeployEnvironmentPageQuery
{
    /**
     * Create a new DeployEnvironmentPageQuery instance.
     *
     * @param  EnvironmentAutomationQuery  $automation  The environment's schedules, tasks and plan.
     * @param  GitHubActionsBuilds  $actions  Writes the workflow each website needs to build on GitHub Actions.
     * @param  ProductionCheck  $check  Lists what isn't right for production.
     * @param  GitHubWorkflowPullRequests  $workflows  Says whether each repository's workflow is up to date.
     */
    public function __construct(private readonly EnvironmentAutomationQuery $automation, private readonly GitHubActionsBuilds $actions, private readonly ProductionCheck $check, private readonly GitHubWorkflowPullRequests $workflows) {}

    /**
     * Describe the environment for the person looking at it: its controls and freezes, how deploys run, variables
     * (secret values never), workers, resources, automation, recipes and deploy notifications.
     *
     * @param  Environment  $environment
     * @param  User  $viewer
     * @return array<string, mixed>
     */
    public function handle(Environment $environment, User $viewer): array
    {
        $environment->load(['variables' => fn ($query) => $query->orderBy('key'), 'processes', 'resources', 'project.account']);
        $project = $environment->project;
        $automation = $this->automation->handle($environment);
        $routes = $environment->deployNotifications()->get()->keyBy('alert_destination_id');

        return [
            'environment' => [
                'id' => $environment->id,
                'name' => $environment->name,
                'protected' => (bool) $environment->protected,
                'requireVariableApproval' => (bool) $environment->require_variable_approval,
                'locked' => $environment->deployment_locked_at !== null,
                'lockReason' => $environment->deployment_lock_reason,
                'windowDays' => $environment->deployment_window_days,
                'windowStart' => $environment->deployment_window_start,
                'windowEnd' => $environment->deployment_window_end,
                'timezone' => $environment->deployment_window_timezone ?? 'UTC',
                'requiresApproval' => (bool) $environment->requires_deployment_approval,
                'automaticRollback' => (bool) $environment->automatic_rollback,
                'migrationSafety' => (bool) $environment->migration_safety,
                'strategy' => $environment->deployment_strategy,
                'rollingPauseSeconds' => $environment->rolling_pause_seconds,
                'observationMinutes' => $environment->post_deployment_observation_minutes,
                'rollbackErrorRatePercent' => $environment->rollback_error_rate_percent,
                'rollbackLatencyPercent' => $environment->rollback_latency_percent,
                'rollbackConversionDropPercent' => $environment->rollback_conversion_drop_percent,
                'runtime' => $environment->runtime_type,
                'runtimeVersion' => $environment->runtime_version,
                'containerPort' => $environment->container_port,
                'buildCommand' => $environment->build_command,
                'startCommand' => $environment->start_command,
                'dockerfilePath' => $environment->dockerfile_path,
                'composeService' => $environment->compose_service,
                'minimumReplicas' => $environment->minimum_replicas,
                'desiredReplicas' => $environment->desired_replicas,
                'maximumReplicas' => $environment->maximum_replicas,
                'autoscaleEnabled' => (bool) $environment->autoscale_enabled,
                'autoscaleCpuTarget' => $environment->autoscale_cpu_target,
                'autoscaleQueueJobs' => $environment->autoscale_queue_jobs,
                'autoscaledAt' => $environment->autoscaled_at?->toIso8601String(),
                'buildServerId' => $environment->build_server_id,
                'artifactBucketId' => $environment->artifact_bucket_id,
                'githubActionsBuilds' => (bool) $environment->github_actions_builds,
                'releaseNotesUrl' => $environment->release_notes_token === null ? null : route('deploy.release-notes.public', $environment->release_notes_token),
                'maintenanceAt' => $environment->maintenance_at?->toIso8601String(),
                'maintenanceSecret' => $environment->maintenance_at === null ? null : $environment->maintenance_secret,
                'maintenanceError' => $environment->maintenance_error,
                'hibernatedAt' => $environment->hibernated_at?->toIso8601String(),
                'lastActivityAt' => $environment->last_activity_at?->toIso8601String(),
                'hibernateAfterMinutes' => $environment->hibernate_after_minutes,
                'recipesRunOnNewWebsites' => (bool) $environment->recipes_run_on_new_websites,
            ],
            'blockReason' => $environment->deploymentBlockReason(),
            'placements' => $environment->deployedWebsites()->load('server.provider')->map(fn (Website $website): array => [
                'name' => $website->name,
                'server' => $website->server?->label(),
                'provider' => $website->server === null ? null : ($website->server->provider->name ?? __('Imported server')),
                'region' => $website->server?->region,
            ])->values(),
            'freezes' => $environment->freezes()->where('ends_at', '>', now())->orderBy('starts_at')->get()->map(fn (EnvironmentFreeze $freeze): array => [
                'id' => $freeze->id,
                'startsAt' => $freeze->starts_at->toIso8601String(),
                'endsAt' => $freeze->ends_at->toIso8601String(),
                'reason' => $freeze->reason,
                'now' => $freeze->starts_at->isPast(),
            ])->values(),
            'variables' => $environment->variables->map(fn (EnvironmentVariable $variable): array => [
                'id' => $variable->id,
                'key' => $variable->key,
                'value' => $variable->is_secret ? null : mb_strimwidth((string) $variable->value, 0, 60, '…'),
                'secret' => (bool) $variable->is_secret,
                'scope' => $variable->scope,
                'version' => $variable->current_version,
                'rotationDueAt' => $variable->rotation_due_at?->toIso8601String(),
            ])->values(),
            'scopes' => array_map(fn (string $label): string => __($label), EnvironmentVariable::SCOPES),
            'pendingChanges' => $environment->pendingVariableChanges()->where('status', 'pending')->with('requester')->latest('id')->get()->map(fn (PendingVariableChange $change): array => [
                'id' => $change->id,
                'summary' => $change->summary,
                'requester' => $change->requester?->name,
                'mine' => $change->requested_by === $viewer->id,
                'createdAt' => $change->created_at?->toIso8601String(),
            ])->values(),
            'secretSyncs' => SecretSync::query()->where('environment_id', $environment->id)->orderBy('name')->get()->map(fn (SecretSync $sync): array => [
                'id' => $sync->id,
                'name' => $sync->name,
                'provider' => SecretSync::PROVIDERS[$sync->provider] ?? $sync->provider,
                'lastSyncedAt' => $sync->last_synced_at?->toIso8601String(),
                'lastError' => $sync->last_error,
                'lastResult' => $sync->last_result,
            ])->values(),
            'secretProviders' => SecretSync::PROVIDERS,
            'processes' => $environment->processes->map(fn (EnvironmentProcess $process): array => [
                'id' => $process->id, 'name' => $process->name, 'command' => $process->command, 'type' => $process->type, 'replicas' => $process->replicas, 'enabled' => (bool) $process->is_enabled,
            ])->values(),
            'resources' => $environment->resources->map(fn (EnvironmentResource $resource): array => [
                'id' => $resource->id,
                'name' => $resource->name,
                'type' => EnvironmentResource::TYPES[$resource->type] ?? $resource->type,
                'managed' => (bool) $resource->is_managed,
                'resettable' => (bool) $resource->is_managed && in_array($resource->type, ['mysql', 'postgresql'], true),
                'variables' => array_keys($resource->configuration['variables'] ?? []),
                'database' => $resource->type === 'mysql' && $resource->is_managed ? ($resource->configuration['variables']['DB_DATABASE'] ?? null) : null,
            ])->values(),
            'databaseWebsites' => $this->databaseWebsites($environment),
            'resourceTypes' => EnvironmentResource::TYPES,
            'deploySchedules' => $automation['deploySchedules']->map(fn (DeploymentSchedule $schedule): array => [
                'id' => $schedule->id, 'name' => $schedule->name, 'cron' => $schedule->cron_expression, 'timezone' => $schedule->timezone,
                'nextRunAt' => $schedule->nextRunAt()?->toIso8601String(), 'lastResult' => $schedule->last_result,
            ])->values(),
            'scalingSchedules' => $automation['scalingSchedules']->map(fn (ScalingSchedule $schedule): array => [
                'id' => $schedule->id, 'name' => $schedule->name, 'replicas' => $schedule->replicas, 'cron' => $schedule->cron_expression, 'timezone' => $schedule->timezone,
                'nextRunAt' => $schedule->nextRunAt()?->toIso8601String(),
            ])->values(),
            'tasks' => $automation['tasks']->map(fn (ScheduledTask $task): array => [
                'id' => $task->id,
                'name' => $task->name,
                'cron' => $task->cron_expression,
                'timezone' => $task->timezone,
                'website' => $task->website->name,
                'timeoutSeconds' => $task->timeout_seconds,
                'lastStatus' => $task->last_status,
                'runs' => $task->runs->map(fn (ScheduledTaskRun $run): array => [
                    'id' => $run->id, 'status' => $run->status, 'durationMs' => $run->duration_ms, 'requester' => $run->requester?->name,
                    'createdAt' => $run->created_at?->toIso8601String(), 'active' => $run->isActive(),
                ])->values(),
            ])->values(),
            'taskWebsites' => $automation['taskWebsites']->map(fn (Website $website): array => ['value' => (string) $website->id, 'label' => $website->name])->values(),
            'plan' => $automation['plan'],
            'hibernationMinutes' => UpdateEnvironmentHibernation::MINUTES,
            'recipes' => $environment->recipes()->with('recipe')->get()->map(fn (EnvironmentRecipe $entry): array => [
                'id' => $entry->id, 'name' => $entry->name, 'deleted' => $entry->recipe === null, 'behind' => $entry->recipe !== null && $entry->isBehind(),
            ])->values(),
            'libraryRecipes' => Recipe::query()->where('account_id', $project->account_id)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Recipe $recipe): array => ['value' => (string) $recipe->id, 'label' => $recipe->name])->values(),
            'destinations' => AlertDestination::query()->forAccount($project->account)->whereNotIn('type', [AlertDestinationType::PagerDuty, AlertDestinationType::Voice])->orderBy('name')->get()
                ->map(function (AlertDestination $destination) use ($routes): array {
                    $route = $routes->get($destination->id);

                    return [
                        'id' => $destination->id, 'name' => $destination->name, 'type' => $destination->type->label(), 'enabled' => (bool) $destination->enabled,
                        'onSuccess' => $route instanceof EnvironmentDeployNotification && $route->on_success,
                        'onFailure' => $route instanceof EnvironmentDeployNotification && $route->on_failure,
                        'onApproval' => $route instanceof EnvironmentDeployNotification && $route->on_approval,
                    ];
                })->values(),
            'buildServers' => Server::query()->where('account_id', $project->account_id)->whereIn('type', array_map(fn (ServerType $type): string => $type->value, BuildServers::TYPES))
                ->where('provisioning_status', Server::STATUS_ACTIVE)->orderBy('name')->get()->map(fn (Server $server): array => ['value' => (string) $server->id, 'label' => $server->label()])->values(),
            'storageBuckets' => StorageBucket::query()->where('project_id', $project->id)->orderBy('name')->get(['id', 'name'])
                ->map(fn (StorageBucket $bucket): array => ['value' => (string) $bucket->id, 'label' => $bucket->name])->values(),
            'githubWorkflows' => $this->githubWorkflows($environment),
            'canManage' => $viewer->can('configureDeploy', $environment),
            'productionCheck' => array_map(fn (ProductionIssue $issue): array => $issue->toArray(), $this->check->issues($environment)),
        ];
    }

    /**
     * The workflow each of the environment's GitHub repositories needs to build on GitHub Actions, where it goes, and
     * whether the repository has it (checked when the environment builds there).
     *
     * @param  Environment  $environment
     * @return list<array{id: int, website: string, repository: string, path: string, yaml: string, status: string, pullRequest: string|null}>
     */
    private function githubWorkflows(Environment $environment): array
    {
        return array_values(Repository::query()->where('environment_id', $environment->id)->with(['provider', 'website', 'environment'])->orderBy('id')->get()
            ->filter(fn (Repository $repository): bool => $this->actions->repositoryName($repository) !== null)
            ->map(fn (Repository $repository): array => [
                'id' => $repository->id,
                'website' => $repository->website->url,
                'repository' => (string) $this->actions->repositoryName($repository),
                'path' => $this->actions->workflowPath($repository),
                'yaml' => $this->actions->workflow($repository),
                'status' => $environment->github_actions_builds ? $this->workflows->status($repository) : 'unknown',
                'pullRequest' => $repository->workflow_pull_request_url,
            ])->all());
    }

    /**
     * The project's websites with a database, for choosing the one a managed MySQL resource uses; the environment's
     * own come first, and the first of those is the default.
     *
     * @param  Environment  $environment
     * @return list<array{value: string, label: string}>
     */
    private function databaseWebsites(Environment $environment): array
    {
        $own = Repository::query()->where('environment_id', $environment->id)->pluck('website_id')->all();
        $ids = [...$own, ...Repository::query()->where('project_id', $environment->project_id)->pluck('website_id')->all(),
            ...Website::query()->whereIn('environment_id', $environment->project->environments()->pluck('id'))->pluck('id')->all()];

        return array_values(Website::query()->whereIn('id', array_unique($ids))->where('has_database', true)->get()
            ->sortBy(fn (Website $website): string => (in_array($website->id, $own, true) ? '0' : '1').$website->url)
            ->map(fn (Website $website): array => ['value' => (string) $website->id, 'label' => (string) __(':website (database :database)', ['website' => $website->url, 'database' => $website->databaseIdentifier()])])
            ->all());
    }
}
