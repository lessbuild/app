<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Data\Telemetry\IssueStatus;
use App\Modules\Monitor\Database\Factories\IssueFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use App\Modules\Monitor\Models\Concerns\HasProjectVisibility;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'application_id',
    'environment_id',
    'fingerprint',
    'type',
    'severity',
    'status',
    'title',
    'location',
    'occurrences',
    'affected_users',
    'first_seen_at',
    'last_seen_at',
    'details',
    'metadata',
])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class Issue extends Model
{
    /** @use HasFactory<IssueFactory> */
    use HasFactory;

    use HasProjectVisibility;

    /** @param Builder<Issue> $query */
    #[Scope]
    protected function forWorkspace(Builder $query, Workspace $workspace): void
    {
        $query->whereHas('application', fn (Builder $application) => $application->whereBelongsTo($workspace));
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return HasMany<IssueActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(IssueActivity::class);
    }

    /** @return HasMany<TelemetryEvent, $this> */
    public function telemetryEvents(): HasMany
    {
        return $this->hasMany(TelemetryEvent::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IssueStatus::class,
            'assignee_id' => 'integer',
            'state_version' => 'integer',
            'resolved_at' => 'immutable_datetime',
            'snoozed_until' => 'immutable_datetime',
            'occurrences' => 'integer',
            'affected_users' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
