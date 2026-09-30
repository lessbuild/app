<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Monitoring\FlowSteps;
use App\Support\Monitoring\QueueMonitorSettings;
use Carbon\CarbonImmutable;
use Database\Factories\MonitorFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $environment_id
 * @property string $name
 * @property string $type
 * @property string|null $request_url
 * @property string|null $bearer_token
 * @property string $method
 * @property int $status_min
 * @property int $status_max
 * @property string|null $body_contains
 * @property int|null $max_duration_ms
 * @property int $timeout_seconds
 * @property int $interval_minutes
 * @property int $trigger_checks
 * @property int $recovery_checks
 * @property bool $enabled
 * @property int $state_version
 * @property int $config_revision
 * @property string $health
 * @property int $failure_streak
 * @property int $recovery_streak
 * @property CarbonImmutable|null $next_check_at
 * @property CarbonImmutable|null $checked_at
 * @property CarbonImmutable|null $last_scheduled_at
 * @property array<string, mixed>|null $observation
 * @property string|null $hostname
 * @property string|null $dns_record_type
 * @property string|null $dns_match
 * @property list<string>|null $dns_expected
 * @property int|null $tls_port
 * @property int|null $tls_expiry_days
 * @property int|null $tcp_port
 * @property string|null $flow_steps a multi-step check's steps (see App\Support\Monitoring\FlowSteps), encrypted
 * @property string|null $heartbeat_schedule
 * @property int|null $heartbeat_interval_minutes
 * @property string|null $heartbeat_cron
 * @property string|null $heartbeat_timezone
 * @property int|null $heartbeat_grace_minutes
 * @property string|null $heartbeat_token_hash
 * @property int|null $heartbeat_sequence
 * @property CarbonImmutable|null $heartbeat_due_at
 * @property CarbonImmutable|null $heartbeat_received_at
 * @property CarbonImmutable|null $heartbeat_succeeded_at
 * @property string|null $queue_name
 * @property array<string, mixed>|null $queue_settings
 * @property string|null $queue_token_hash
 * @property CarbonImmutable|null $queue_started_at
 * @property int|null $queue_snapshot_id
 * @property int|null $legacy_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property mixed $deleted_at
 * @property-read Environment $environment
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MonitorCheck> $checks
 * @property-read \Illuminate\Database\Eloquent\Collection<int, HeartbeatRun> $heartbeatRuns
 * @property-read \Illuminate\Database\Eloquent\Collection<int, QueueSnapshot> $queueSnapshots
 * @property-read \Illuminate\Database\Eloquent\Collection<int, QueueWorker> $queueWorkers
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Incident> $incidents
 * @property-read \Illuminate\Database\Eloquent\Collection<int, AlertDestination> $destinations
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(MonitorFactory::class)]
class Monitor extends Model
{
    /** @use HasFactory<MonitorFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Targets, credentials and job token hashes never leave the server in serialised form.
     *
     * @var list<string>
     */
    protected $hidden = ['request_url', 'bearer_token', 'body_contains', 'hostname', 'dns_expected', 'heartbeat_token_hash', 'queue_token_hash'];

    /**
     * Limit a query to monitors in the account's environments.
     *
     * @param  Builder<Monitor>  $query
     * @param  Account  $account
     * @return void
     */
    #[Scope]
    protected function forAccount(Builder $query, Account $account): void
    {
        $query->whereIn('environment_id', Environment::forAccount($account)->select('id'));
    }

    /**
     * Get the environment the monitor belongs to.
     *
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * Get the monitor's scheduled and finished checks.
     *
     * @return HasMany<MonitorCheck, $this>
     */
    public function checks(): HasMany
    {
        return $this->hasMany(MonitorCheck::class);
    }

    /**
     * Get the pings from the job a heartbeat monitor watches.
     *
     * @return HasMany<HeartbeatRun, $this>
     */
    public function heartbeatRuns(): HasMany
    {
        return $this->hasMany(HeartbeatRun::class);
    }

    /**
     * Get the reports from the queue a queue monitor watches.
     *
     * @return HasMany<QueueSnapshot, $this>
     */
    public function queueSnapshots(): HasMany
    {
        return $this->hasMany(QueueSnapshot::class);
    }

    /**
     * Get the workers seen by a queue monitor.
     *
     * @return HasMany<QueueWorker, $this>
     */
    public function queueWorkers(): HasMany
    {
        return $this->hasMany(QueueWorker::class);
    }

    /**
     * Get the incidents the monitor has opened.
     *
     * @return HasMany<Incident, $this>
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    /**
     * Get the destinations the monitor sends alerts to.
     *
     * @return BelongsToMany<AlertDestination, $this>
     */
    public function destinations(): BelongsToMany
    {
        return $this->belongsToMany(AlertDestination::class)->withPivot(['opened', 'recovered']);
    }

    /**
     * Describe what the monitor watches, safe to show: the queue, the heartbeat schedule, the host and port, or a URL
     * cut down to its scheme, host and port, since paths and queries can hold tokens.
     *
     * @return string
     */
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
        if ($this->type === 'flow') {
            $steps = FlowSteps::parse((string) $this->flow_steps)['steps'];

            return ($steps === [] ? 'hidden' : (parse_url($steps[0]['url'], PHP_URL_HOST) ?: 'hidden')).' · '.count($steps).' steps';
        }
        if ($this->type !== 'http') {
            return ($this->hostname ?? 'hidden').(in_array($this->type, ['tls', 'tcp'], true) ? ':'.$this->{$this->type.'_port'} : '');
        }
        $parts = parse_url($this->request_url ?? '');
        if ($parts === false) {
            return 'hidden';
        }

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'hidden')
            .(isset($parts['port']) ? ':'.$parts['port'] : '').'/…';
    }

    /**
     * Get the queue thresholds, with defaults for anything not stored. Required values are always ints; a null maximum
     * is off.
     *
     * @return array{report_timeout_seconds: int, worker_timeout_seconds: int, minimum_workers: int, max_runtime_seconds: int|null, max_pending: int|null, max_delayed: int|null, max_reserved: int|null, max_failed: int|null, max_oldest_wait_seconds: int|null}
     */
    public function queueThresholds(): array
    {
        $stored = $this->queue_settings ?? [];
        $value = static fn (string $key): ?int => array_key_exists($key, $stored)
            ? (is_numeric($stored[$key]) ? (int) $stored[$key] : null)
            : QueueMonitorSettings::DEFAULTS[$key];

        return [
            'report_timeout_seconds' => $value('report_timeout_seconds') ?? QueueMonitorSettings::DEFAULTS['report_timeout_seconds'],
            'worker_timeout_seconds' => $value('worker_timeout_seconds') ?? QueueMonitorSettings::DEFAULTS['worker_timeout_seconds'],
            'minimum_workers' => $value('minimum_workers') ?? QueueMonitorSettings::DEFAULTS['minimum_workers'],
            'max_runtime_seconds' => $value('max_runtime_seconds'),
            'max_pending' => $value('max_pending'), 'max_delayed' => $value('max_delayed'),
            'max_reserved' => $value('max_reserved'), 'max_failed' => $value('max_failed'),
            'max_oldest_wait_seconds' => $value('max_oldest_wait_seconds'),
        ];
    }

    /**
     * Get the monitor type as people read it.
     *
     * @return string
     */
    public function typeLabel(): string
    {
        return match ($this->type) {
            'queue' => 'Queue / workers',
            'heartbeat' => 'Cron / heartbeat',
            'dns' => 'DNS records',
            'tls' => 'TLS certificate',
            'tcp' => 'TCP port',
            'flow' => 'Multi-step check',
            default => 'HTTP uptime',
        };
    }

    /**
     * Describe the monitor's state for lists and status pages: archived, paused, unknown when its latest result is
     * missing or overdue, or up or down from that result.
     *
     * @return string
     */
    public function healthLabel(): string
    {
        if ($this->trashed()) {
            return 'Archived';
        }
        if (! $this->enabled) {
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

    /**
     * Capture the monitor's settings as they are when an incident opens, to store on the incident. Only the fields
     * that matter for the monitor's type are kept, and never its secrets.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        if ($this->type === 'queue') {
            return $this->only(['name', 'type', 'queue_name', 'queue_settings', 'trigger_checks', 'recovery_checks']);
        }
        if ($this->type === 'heartbeat') {
            return $this->only(['name', 'type', 'heartbeat_schedule', 'heartbeat_interval_minutes',
                'heartbeat_cron', 'heartbeat_timezone', 'heartbeat_grace_minutes', 'trigger_checks', 'recovery_checks']);
        }
        if ($this->type === 'flow') {
            return [...$this->only(['name', 'type', 'timeout_seconds', 'interval_minutes', 'trigger_checks', 'recovery_checks']), 'step_count' => count(FlowSteps::parse((string) $this->flow_steps)['steps'])];
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

    /**
     * Get the attributes that should be cast.
     *
     * Encrypts the target and credentials (URL, bearer token, expected body text, hostname, expected DNS answers) and
     * reads the queue settings and latest observation as JSON.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'queue_settings' => 'array', 'queue_started_at' => 'immutable_datetime', 'queue_snapshot_id' => 'integer',
            'heartbeat_interval_minutes' => 'integer', 'heartbeat_grace_minutes' => 'integer',
            'heartbeat_sequence' => 'integer', 'heartbeat_due_at' => 'immutable_datetime',
            'heartbeat_received_at' => 'immutable_datetime', 'heartbeat_succeeded_at' => 'immutable_datetime',
            'request_url' => 'encrypted', 'bearer_token' => 'encrypted', 'body_contains' => 'encrypted',
            'hostname' => 'encrypted', 'dns_expected' => 'encrypted:array', 'flow_steps' => 'encrypted',
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
