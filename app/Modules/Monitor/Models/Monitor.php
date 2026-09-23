<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\MonitorFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class Monitor extends Model
{
    /** @use HasFactory<MonitorFactory> */
    use HasFactory, SoftDeletes;

    protected $hidden = ['request_url', 'bearer_token', 'body_contains', 'hostname', 'dns_expected', 'heartbeat_token_hash', 'queue_token_hash'];

    /** @param Builder<Monitor> $query */
    #[Scope]
    protected function forWorkspace(Builder $query, Workspace $workspace): void
    {
        $query->whereIn('environment_id', Environment::forWorkspace($workspace)->select('id'));
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return HasMany<MonitorCheck, $this> */
    public function checks(): HasMany
    {
        return $this->hasMany(MonitorCheck::class);
    }

    /** @return HasMany<HeartbeatRun, $this> */
    public function heartbeatRuns(): HasMany
    {
        return $this->hasMany(HeartbeatRun::class);
    }

    /** @return HasMany<QueueSnapshot, $this> */
    public function queueSnapshots(): HasMany
    {
        return $this->hasMany(QueueSnapshot::class);
    }

    /** @return HasMany<QueueWorker, $this> */
    public function queueWorkers(): HasMany
    {
        return $this->hasMany(QueueWorker::class);
    }

    /** @return HasMany<Incident, $this> */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    /** @return BelongsToMany<AlertDestination, $this> */
    public function destinations(): BelongsToMany
    {
        return $this->belongsToMany(AlertDestination::class)->withPivot(['opened', 'recovered']);
    }

    public function targetLabel(): string
    {
        if ($this->type === 'queue') {
            return 'Queue: '.$this->queue_name;
        }
        if ($this->type === 'heartbeat') {
            return $this->heartbeat_schedule === 'cron'
                ? $this->heartbeat_cron.' · '.$this->heartbeat_timezone
                : 'Heartbeat every '.$this->heartbeat_interval_minutes.' min';
        }
        if ($this->type !== 'http') {
            return ($this->hostname ?? 'hidden').(in_array($this->type, ['tls', 'tcp'], true) ? ':'.$this->{$this->type.'_port'} : '');
        }
        $parts = parse_url($this->request_url);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'hidden')
            .(isset($parts['port']) ? ':'.$parts['port'] : '').'/…';
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'queue' => 'Queue / workers',
            'heartbeat' => 'Cron / heartbeat',
            'dns' => 'DNS records',
            'tls' => 'TLS certificate',
            'tcp' => 'TCP port',
            default => 'HTTP uptime',
        };
    }

    public function healthLabel(): string
    {
        if ($this->trashed()) {
            return 'Archived';
        }
        if (! $this->enabled || $this->environment?->status !== 'active') {
            return 'Paused';
        }
        if (in_array($this->type, ['heartbeat', 'queue'], true)) {
            if ($this->next_check_at?->addSeconds(120)->isPast()) {
                return 'Unknown';
            }

            return ucfirst($this->health);
        }
        if ($this->checked_at === null || $this->checked_at->addMinutes($this->interval_minutes * 2)->addSeconds(120)->isPast()) {
            return 'Unknown';
        }

        return ucfirst($this->health);
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        if ($this->type === 'queue') {
            return $this->only(['name', 'type', 'queue_name', 'queue_settings', 'trigger_checks', 'recovery_checks']);
        }
        if ($this->type === 'heartbeat') {
            return $this->only(['name', 'type', 'heartbeat_schedule', 'heartbeat_interval_minutes',
                'heartbeat_cron', 'heartbeat_timezone', 'heartbeat_grace_minutes', 'trigger_checks', 'recovery_checks']);
        }
        if ($this->type !== 'http') {
            return [...$this->only(['name', 'type', 'timeout_seconds', 'interval_minutes', 'trigger_checks', 'recovery_checks']),
                ...($this->type === 'dns'
                    ? [...$this->only(['dns_record_type', 'dns_match']), 'expected_count' => count($this->dns_expected ?? [])]
                    : ($this->type === 'tls' ? $this->only(['tls_port', 'tls_expiry_days']) : $this->only(['tcp_port'])))];
        }

        return [...$this->only(['name', 'type', 'method', 'status_min', 'status_max', 'max_duration_ms', 'timeout_seconds', 'interval_minutes', 'trigger_checks', 'recovery_checks']),
            'body_assertion' => $this->body_contains !== null, 'authenticated' => $this->bearer_token !== null];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'queue_settings' => 'array', 'queue_started_at' => 'immutable_datetime', 'queue_snapshot_id' => 'integer',
            'heartbeat_interval_minutes' => 'integer', 'heartbeat_grace_minutes' => 'integer',
            'heartbeat_sequence' => 'integer', 'heartbeat_due_at' => 'immutable_datetime',
            'heartbeat_received_at' => 'immutable_datetime', 'heartbeat_succeeded_at' => 'immutable_datetime',
            'request_url' => 'encrypted', 'bearer_token' => 'encrypted', 'body_contains' => 'encrypted',
            'hostname' => 'encrypted', 'dns_expected' => 'encrypted:array',
            'tls_port' => 'integer', 'tls_expiry_days' => 'integer',
            'tcp_port' => 'integer',
            'enabled' => 'boolean', 'state_version' => 'integer', 'config_revision' => 'integer',
            'status_min' => 'integer', 'status_max' => 'integer', 'max_duration_ms' => 'integer',
            'timeout_seconds' => 'integer', 'interval_minutes' => 'integer',
            'trigger_checks' => 'integer', 'recovery_checks' => 'integer',
            'failure_streak' => 'integer', 'recovery_streak' => 'integer',
            'checked_at' => 'immutable_datetime', 'next_check_at' => 'immutable_datetime',
            'last_scheduled_at' => 'immutable_datetime', 'observation' => 'array',
        ];
    }
}
