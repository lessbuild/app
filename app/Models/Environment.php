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

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function deploymentLocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deployment_locked_by');
    }

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

    public function variables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class);
    }

    public function builds(): HasMany
    {
        return $this->hasMany(Build::class);
    }

    public function processes(): HasMany
    {
        return $this->hasMany(EnvironmentProcess::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(EnvironmentResource::class);
    }

    public function deploymentSchedules(): HasMany
    {
        return $this->hasMany(DeploymentSchedule::class);
    }

    public function scalingSchedules(): HasMany
    {
        return $this->hasMany(ScalingSchedule::class);
    }

    public function scheduledTasks(): HasMany
    {
        return $this->hasMany(ScheduledTask::class);
    }

    public function loadBalancers(): HasMany
    {
        return $this->hasMany(LoadBalancer::class);
    }
}
