<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Environment extends Model
{
    public const TYPES = ['production', 'staging', 'development', 'preview'];

    public const DEPLOYMENT_STRATEGIES = ['blue_green', 'canary', 'rolling'];

    public const RUNTIME_TYPES = ['php', 'node', 'python', 'docker'];

    protected $guarded = [];

    protected $casts = [
        'is_protected' => 'boolean',
        'requires_deployment_approval' => 'boolean',
        'automatic_rollback' => 'boolean',
        'minimum_replicas' => 'integer',
        'maximum_replicas' => 'integer',
        'hibernate_after_minutes' => 'integer',
        'desired_replicas' => 'integer',
        'last_activity_at' => 'datetime',
        'hibernated_at' => 'datetime',
        'deployment_locked_at' => 'datetime',
        'deployment_window_days' => 'array',
        'rolling_pause_seconds' => 'integer',
        'container_port' => 'integer',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<Website, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /** @return BelongsTo<User, $this> */
    public function deploymentLocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deployment_locked_by');
    }

    /**
     * Evaluate deployment locks and the configured local maintenance window.
     *
     * @param  ?Carbon  $at  The time to evaluate; defaults to the current time without mutating the input.
     * @return ?string The reason deployment is blocked, or null when deployment is allowed.
     */
    public function deploymentBlockReason(?Carbon $at = null): ?string
    {
        if ($this->deployment_locked_at) {
            return $this->deployment_lock_reason ?: __('Deployments are locked for this environment.');
        }

        $days = array_map('intval', $this->deployment_window_days ?? []);
        if ($days === [] || ! $this->deployment_window_start || ! $this->deployment_window_end) {
            return null;
        }

        $timezone = $this->deployment_window_timezone ?: 'UTC';
        $local = ($at ?? now())->copy()->setTimezone($timezone);
        $time = $local->format('H:i:s');
        $start = $this->deployment_window_start;
        $end = $this->deployment_window_end;
        $inside = $start < $end
            ? in_array($local->dayOfWeekIso, $days, true) && $time >= $start && $time < $end
            : (in_array($local->dayOfWeekIso, $days, true) && $time >= $start)
                || (in_array($local->copy()->subDay()->dayOfWeekIso, $days, true) && $time < $end);

        return $inside ? null : __('Deployment is outside this environment’s maintenance window.');
    }

    /** @return HasMany<EnvironmentVariable, $this> */
    public function variables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class);
    }

    /** @return HasMany<Build, $this> */
    public function builds(): HasMany
    {
        return $this->hasMany(Build::class);
    }

    /** @return HasMany<EnvironmentProcess, $this> */
    public function processes(): HasMany
    {
        return $this->hasMany(EnvironmentProcess::class);
    }

    /** @return HasMany<EnvironmentResource, $this> */
    public function resources(): HasMany
    {
        return $this->hasMany(EnvironmentResource::class);
    }

    /** @return HasMany<DeploymentSchedule, $this> */
    public function deploymentSchedules(): HasMany
    {
        return $this->hasMany(DeploymentSchedule::class);
    }

    /** @return HasMany<ScalingSchedule, $this> */
    public function scalingSchedules(): HasMany
    {
        return $this->hasMany(ScalingSchedule::class);
    }

    /** @return HasMany<ScheduledTask, $this> */
    public function scheduledTasks(): HasMany
    {
        return $this->hasMany(ScheduledTask::class);
    }

    /** @return HasMany<LoadBalancer, $this> */
    public function loadBalancers(): HasMany
    {
        return $this->hasMany(LoadBalancer::class);
    }
}
