<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $account_id
 * @property string|null $project_id
 * @property int|null $alert_rule_id
 * @property int|null $monitor_id
 * @property bool|null $active_slot
 * @property string $title
 * @property string $status
 * @property int $state_version
 * @property array<string, mixed> $rule_snapshot
 * @property array<string, mixed> $opening_observation
 * @property array<string, mixed> $latest_observation
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable $last_breached_at
 * @property CarbonImmutable|null $acknowledged_at
 * @property string|null $acknowledged_by
 * @property string|null $assignee_id
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $closure_reason
 * @property int|null $legacy_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Account $account
 * @property-read Project|null $project
 * @property-read Monitor|null $monitor
 * @property-read AlertRule|null $alertRule
 * @property-read User|null $acknowledgedBy
 * @property-read User|null $assignee
 * @property-read \Illuminate\Database\Eloquent\Collection<int, IncidentActivity> $activities
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(IncidentFactory::class)]
class Incident extends Model
{
    /** @use HasFactory<IncidentFactory> */
    use HasFactory;

    /**
     * Limit a query to the account's incidents.
     *
     * @param  Builder<Incident>  $query
     * @param  Account  $account
     * @return void
     */
    #[Scope]
    protected function forAccount(Builder $query, Account $account): void
    {
        $query->where('account_id', $account->id);
    }

    /**
     * Get the account the incident belongs to.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Get the project it happened in.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the monitor that opened it, including archived ones.
     *
     * @return BelongsTo<Monitor, $this>
     */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class)->withTrashed();
    }

    /**
     * Get the alert rule that opened it, including archived ones.
     *
     * @return BelongsTo<AlertRule, $this>
     */
    public function alertRule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class)->withTrashed();
    }

    /**
     * Get what opened the incident: a monitor, or an alert rule on telemetry.
     *
     * @return Monitor|AlertRule|null
     */
    public function source(): Monitor|AlertRule|null
    {
        return $this->monitor_id !== null ? $this->monitor : $this->alertRule;
    }

    /**
     * Get the person who acknowledged the incident (`acknowledged_by`).
     *
     * @return BelongsTo<User, $this>
     */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /**
     * Get the person working on the incident (`assignee_id`).
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * Get the incident's timeline: opening, acknowledgements, notes, assignments and closure.
     *
     * @return HasMany<IncidentActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(IncidentActivity::class);
    }

    /**
     * Describe the status as people read it, with why a resolved incident closed (recovered, rule changed, monitor
     * archived…).
     *
     * @return string
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            'acknowledged' => 'Acknowledged',
            'resolved' => match ($this->closure_reason) {
                'recovered' => 'Recovered',
                'rule_changed' => 'Closed: rule changed',
                'rule_archived' => 'Closed: rule archived',
                'monitor_changed' => 'Closed: monitor changed',
                'monitor_archived' => 'Closed: monitor archived',
                default => 'Closed',
            },
            default => 'Open',
        };
    }

    /**
     * Get the attributes that should be cast.
     *
     * Reads the rule snapshot and the opening and latest observations as JSON.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active_slot' => 'boolean', 'state_version' => 'integer',
            'rule_snapshot' => 'array', 'opening_observation' => 'array', 'latest_observation' => 'array',
            'opened_at' => 'immutable_datetime', 'last_breached_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime',
        ];
    }
}
