<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EnvironmentKind;
use Database\Factories\EnvironmentFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $project_id
 * @property string $name
 * @property string $slug
 * @property EnvironmentKind $kind
 * @property int $telemetry_event_count events Monitoring has received for this environment
 * @property \Carbon\CarbonImmutable|null $telemetry_last_received_at
 * @property bool $requires_deployment_approval Deploy: builds wait for someone else to approve them
 * @property bool $require_variable_approval changes to its variables wait for someone else's approval
 * @property bool $protected only owners, admins and members allowed to deploy protected environments can deploy to or change it
 * @property \Carbon\CarbonImmutable|null $deployment_locked_at Deploy: no deploys while locked
 * @property string|null $deployment_locked_by
 * @property string|null $deployment_lock_reason
 * @property list<int>|null $deployment_window_days ISO weekdays (1 = Monday) deploys are allowed on
 * @property string|null $deployment_window_start HH:MM
 * @property string|null $deployment_window_end HH:MM (earlier than the start means past midnight)
 * @property string|null $deployment_window_timezone
 * @property string $deployment_strategy blue_green or canary (canary checks the new release before it goes live)
 * @property int $rolling_pause_seconds pause between restarting each process replica
 * @property bool $automatic_rollback a deploy that fails after going live, or fails its observation, rolls back
 * @property int|null $post_deployment_observation_minutes watch the website's health this long after each deploy
 * @property int|null $rollback_error_rate_percent while watching, fail the deploy when more than this share of requests fail (and more than before it)
 * @property string $runtime_type php, node, python or docker
 * @property string|null $runtime_version
 * @property string|null $build_command
 * @property string|null $start_command for node, python and docker runtimes
 * @property int|null $container_port
 * @property string|null $dockerfile_path
 * @property int $minimum_replicas
 * @property int $maximum_replicas
 * @property int $desired_replicas
 * @property int|null $hibernate_after_minutes hibernate after this long without requests
 * @property \Carbon\CarbonImmutable|null $last_activity_at the last request or deploy seen, for hibernation
 * @property bool $recipes_run_on_new_websites run the environment's recipes on a website's server when it finishes setting up
 * @property \Carbon\CarbonImmutable|null $hibernated_at when it went to sleep; null while running
 * @property int|null $legacy_id Deployer's numeric ID, which the Deployer API v1 still accepts
 * @property-read Project $project
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EnvironmentVariable> $variables
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EnvironmentProcess> $processes
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EnvironmentResource> $resources
 */
#[UseFactory(EnvironmentFactory::class)]
class Environment extends Model
{
    /** @use HasFactory<EnvironmentFactory> */
    use HasFactory, HasUlids;

    /**
     * Get the attributes that should be cast.
     *
     * Reads `kind` as an EnvironmentKind and the deployment window days as JSON.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => EnvironmentKind::class, 'telemetry_event_count' => 'integer', 'telemetry_last_received_at' => 'immutable_datetime',
            'requires_deployment_approval' => 'boolean', 'protected' => 'boolean', 'require_variable_approval' => 'boolean', 'deployment_locked_at' => 'immutable_datetime', 'deployment_window_days' => 'array',
            'rolling_pause_seconds' => 'integer', 'automatic_rollback' => 'boolean', 'post_deployment_observation_minutes' => 'integer', 'rollback_error_rate_percent' => 'integer',
            'container_port' => 'integer', 'minimum_replicas' => 'integer', 'maximum_replicas' => 'integer', 'desired_replicas' => 'integer',
            'hibernate_after_minutes' => 'integer', 'last_activity_at' => 'immutable_datetime', 'hibernated_at' => 'immutable_datetime', 'recipes_run_on_new_websites' => 'boolean',
        ];
    }

    /**
     * Limit a query to environments of the account's projects.
     *
     * @param  Builder<Environment>  $query
     * @param  Account  $account
     * @return void
     */
    #[Scope]
    protected function forAccount(Builder $query, Account $account): void
    {
        $query->whereIn('project_id', Project::query()->where('account_id', $account->id)->select('id'));
    }

    /**
     * Get the project the environment belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the keys that send telemetry to the environment.
     *
     * @return HasMany<IngestToken, $this>
     */
    public function ingestTokens(): HasMany
    {
        return $this->hasMany(IngestToken::class);
    }

    /**
     * Get the releases reported as deployed here.
     *
     * @return HasMany<Deployment, $this>
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    /**
     * Get the repositories that deploy to this environment.
     *
     * @return HasMany<Repository, $this>
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }

    /**
     * Get the environment's deploy environment variables.
     *
     * @return HasMany<EnvironmentVariable, $this>
     */
    public function variables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class);
    }

    /**
     * Get the environment's long-running processes (workers, schedulers).
     *
     * @return HasMany<EnvironmentProcess, $this>
     */
    public function processes(): HasMany
    {
        return $this->hasMany(EnvironmentProcess::class);
    }

    /**
     * Get the environment's attached resources (databases, caches).
     *
     * @return HasMany<EnvironmentResource, $this>
     */
    public function resources(): HasMany
    {
        return $this->hasMany(EnvironmentResource::class);
    }

    /**
     * Get the environment's scheduled deploys.
     *
     * @return HasMany<DeploymentSchedule, $this>
     */
    public function deploymentSchedules(): HasMany
    {
        return $this->hasMany(DeploymentSchedule::class);
    }

    /**
     * Get the environment's scaling schedules.
     *
     * @return HasMany<ScalingSchedule, $this>
     */
    public function scalingSchedules(): HasMany
    {
        return $this->hasMany(ScalingSchedule::class);
    }

    /**
     * Get the destinations that hear about this environment's deploys.
     *
     * @return HasMany<EnvironmentDeployNotification, $this>
     */
    public function deployNotifications(): HasMany
    {
        return $this->hasMany(EnvironmentDeployNotification::class);
    }

    /**
     * Get the variable changes waiting for approval, and those decided.
     *
     * @return HasMany<PendingVariableChange, $this>
     */
    public function pendingVariableChanges(): HasMany
    {
        return $this->hasMany(PendingVariableChange::class);
    }

    /**
     * Get the periods when the environment takes no deploys.
     *
     * @return HasMany<EnvironmentFreeze, $this>
     */
    public function freezes(): HasMany
    {
        return $this->hasMany(EnvironmentFreeze::class);
    }

    /**
     * Get the environment's recipes, in the order they run.
     *
     * @return HasMany<EnvironmentRecipe, $this>
     */
    public function recipes(): HasMany
    {
        return $this->hasMany(EnvironmentRecipe::class)->orderBy('position');
    }

    /**
     * Get the environment's scheduled tasks.
     *
     * @return HasMany<ScheduledTask, $this>
     */
    public function scheduledTasks(): HasMany
    {
        return $this->hasMany(ScheduledTask::class);
    }

    /**
     * Get the live websites the environment's repositories deploy to (not previews' repositories), where its
     * processes run and its tasks can.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Website>
     */
    public function deployedWebsites(): \Illuminate\Database\Eloquent\Collection
    {
        return Website::query()->whereIn('id', $this->repositories()->select('website_id'))->with('server')->orderBy('name')->get();
    }

    /**
     * Get the preview this environment belongs to, when it's a preview's own environment.
     *
     * @return HasOne<Preview, $this>
     */
    public function preview(): HasOne
    {
        return $this->hasOne(Preview::class);
    }

    /**
     * Explain why a deploy can't start now (locked, frozen, or outside the deployment window), or return null.
     *
     * @param  \Carbon\CarbonInterface|null  $at
     * @return string|null
     */
    public function deploymentBlockReason(?\Carbon\CarbonInterface $at = null): ?string
    {
        if ($this->deployment_locked_at !== null) {
            return $this->deployment_lock_reason ?: (string) __('Deploys are locked for this environment.');
        }
        $moment = \Carbon\CarbonImmutable::instance($at ?? now())->utc();
        $freeze = $this->freezes()->where('starts_at', '<=', $moment)->where('ends_at', '>', $moment)->orderByDesc('ends_at')->first();
        if ($freeze !== null) {
            return $freeze->reason !== null
                ? (string) __('Deploys are frozen until :time: :reason', ['time' => $freeze->ends_at->toDayDateTimeString().' UTC', 'reason' => $freeze->reason])
                : (string) __('Deploys are frozen until :time.', ['time' => $freeze->ends_at->toDayDateTimeString().' UTC']);
        }
        $days = array_map('intval', $this->deployment_window_days ?? []);
        if ($days === [] || $this->deployment_window_start === null || $this->deployment_window_end === null) {
            return null;
        }
        $local = \Carbon\CarbonImmutable::instance($at ?? now())->setTimezone($this->deployment_window_timezone ?: 'UTC');
        $time = $local->format('H:i');
        [$start, $end] = [$this->deployment_window_start, $this->deployment_window_end];
        $inside = $start < $end
            ? in_array($local->dayOfWeekIso, $days, true) && $time >= $start && $time < $end
            : (in_array($local->dayOfWeekIso, $days, true) && $time >= $start) || (in_array($local->subDay()->dayOfWeekIso, $days, true) && $time < $end);

        return $inside ? null : (string) __('Deploys are only allowed in this environment’s deployment window.');
    }
}
