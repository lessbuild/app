<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class Incident extends Model
{
    /** @use HasFactory<IncidentFactory> */
    use HasFactory;

    /** @param Builder<Incident> $query */
    #[Scope]
    protected function forWorkspace(Builder $query, Workspace $workspace): void
    {
        $query->where(function (Builder $sources) use ($workspace): void {
            $sources->whereIn('alert_rule_id', AlertRule::withTrashed()->forWorkspace($workspace)->select('id'))
                ->orWhereIn('monitor_id', Monitor::withTrashed()->forWorkspace($workspace)->select('id'));
        });
    }

    /** @return BelongsTo<AlertRule, $this> */
    public function alertRule(): BelongsTo
    {
        return $this->belongsTo(AlertRule::class)->withTrashed();
    }

    /** @return BelongsTo<Monitor, $this> */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class)->withTrashed();
    }

    public function source(): AlertRule|Monitor|null
    {
        return $this->monitor_id !== null ? $this->monitor : $this->alertRule;
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return HasMany<IncidentActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(IncidentActivity::class);
    }

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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'active_slot' => 'boolean', 'state_version' => 'integer',
            'assignee_id' => 'integer',
            'rule_snapshot' => 'array', 'opening_observation' => 'array', 'latest_observation' => 'array',
            'opened_at' => 'immutable_datetime', 'last_breached_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime',
        ];
    }
}
