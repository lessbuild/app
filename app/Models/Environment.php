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

/**
 * @property string $id
 * @property string $project_id
 * @property string $name
 * @property string $slug
 * @property EnvironmentKind $kind
 * @property int $telemetry_event_count events Monitoring has received for this environment
 * @property \Carbon\CarbonImmutable|null $telemetry_last_received_at
 * @property bool $requires_deployment_approval Deploy: builds wait for someone else to approve them
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
 * @property string $runtime_type php, node, python or docker
 * @property string|null $runtime_version
 * @property string|null $build_command
 * @property string|null $start_command for node, python and docker runtimes
 * @property int|null $container_port
 * @property string|null $dockerfile_path
 * @property int $minimum_replicas
 * @property int $maximum_replicas
 * @property int $desired_replicas
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
            'requires_deployment_approval' => 'boolean', 'deployment_locked_at' => 'immutable_datetime', 'deployment_window_days' => 'array',
            'rolling_pause_seconds' => 'integer', 'automatic_rollback' => 'boolean', 'post_deployment_observation_minutes' => 'integer',
            'container_port' => 'integer', 'minimum_replicas' => 'integer', 'maximum_replicas' => 'integer', 'desired_replicas' => 'integer',
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
     * Explain why a deploy can't start now (locked, or outside the deployment window), or return null.
     *
     * @param  \Carbon\CarbonInterface|null  $at
     * @return string|null
     */
    public function deploymentBlockReason(?\Carbon\CarbonInterface $at = null): ?string
    {
        if ($this->deployment_locked_at !== null) {
            return $this->deployment_lock_reason ?: (string) __('Deploys are locked for this environment.');
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
