<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IssueStatus;
use Carbon\CarbonImmutable;
use Database\Factories\IssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property string $project_id
 * @property string|null $environment_id
 * @property string $fingerprint
 * @property string $type
 * @property string $severity
 * @property IssueStatus $status
 * @property string $title
 * @property string|null $location
 * @property int $occurrences
 * @property int $affected_users
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property string|null $details
 * @property array<string, mixed>|null $metadata
 * @property string|null $assignee_id
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $snoozed_until
 * @property int $state_version
 * @property int|null $legacy_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Environment|null $environment
 * @property-read User|null $assignee
 * @property-read \Illuminate\Database\Eloquent\Collection<int, IssueActivity> $activities
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TelemetryEvent> $telemetryEvents
 */
#[Fillable([
    'project_id',
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
#[UseFactory(IssueFactory::class)]
final class Issue extends Model
{
    /** @use HasFactory<IssueFactory> */
    use HasFactory;

    /** @param Builder<Issue> $query */
    #[Scope]
    protected function forAccount(Builder $query, Account $account): void
    {
        $query->whereHas('project', fn (Builder $project) => $project->whereBelongsTo($account));
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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
