<?php

namespace App\Models;

use App\Enums\BuildStatus;
use App\Models\Concerns\HasDuration;
use App\Presenters\BuildPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Build extends Model
{
    use HasDuration, HasFactory;

    public const STATUS_QUEUED = BuildStatus::Queued->value;

    public const STATUS_AWAITING_APPROVAL = BuildStatus::AwaitingApproval->value;

    public const STATUS_REJECTED = BuildStatus::Rejected->value;

    public const STATUS_DEPLOYING = BuildStatus::Deploying->value;

    public const STATUS_RUNNING = BuildStatus::Running->value;

    public const STATUS_TIMING_OUT = BuildStatus::TimingOut->value;

    public const STATUS_SUCCEEDED = BuildStatus::Succeeded->value;

    public const STATUS_FAILED = BuildStatus::Failed->value;

    public const STATUS_CANCELED = BuildStatus::Canceled->value;

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_WEBHOOK = 'webhook';

    public const TRIGGER_REDEPLOY = 'redeploy';

    public const TRIGGER_ROLLBACK = 'rollback';

    public const TRIGGER_SCHEDULED = 'scheduled';

    public const TRIGGER_API = 'api';

    public const TRIGGER_PROMOTION = 'promotion';

    public const ACTIVE_STATUSES = BuildStatus::ACTIVE_VALUES;

    public const TERMINAL_STATUSES = BuildStatus::TERMINAL_VALUES;

    public const DEPLOYMENT_LOG_TYPE = 'deployment';

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    protected $hidden = ['environment_payload'];

    protected $casts = [
        'setup_stage' => 'integer',
        'built_at' => 'datetime',
        'remote_process_id' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'activated_at' => 'datetime',
        'environment_payload' => 'encrypted:array',
        'risk_assessment' => 'array',
        'promotion_note' => 'encrypted',
    ];

    /**
     * Interpret the stored status without changing its serialized string representation.
     *
     * @return BuildStatus|null The known lifecycle state, or null for absent or legacy values.
     */
    public function statusEnum(): ?BuildStatus
    {
        $status = $this->getAttribute('status');

        return $status instanceof BuildStatus
            ? $status
            : (is_string($status) ? BuildStatus::tryFrom($status) : null);
    }

    /** @return MorphMany<Log, $this> */
    public function logs(): MorphMany
    {
        return $this->morphMany(Log::class, 'parentable');
    }

    /** @return MorphMany<Event, $this> */
    public function events(): MorphMany
    {
        return $this->morphMany(Event::class, 'parentable');
    }

    /** @return BelongsTo<Repository, $this> */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<Build, $this> */
    public function redeployedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'redeployed_from_build_id');
    }

    /** @return BelongsTo<Build, $this> */
    public function rolledBackFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rolled_back_from_build_id');
    }

    /** @return BelongsTo<Build, $this> */
    public function automaticRollbackBuild(): BelongsTo
    {
        return $this->belongsTo(self::class, 'automatic_rollback_build_id');
    }

    /** @return BelongsTo<Build, $this> */
    public function promotedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'promoted_from_build_id');
    }

    /** @return HasMany<Build, $this> */
    public function promotions(): HasMany
    {
        return $this->hasMany(self::class, 'promoted_from_build_id');
    }

    /** @return string|null The abbreviated revision for display, or null when unknown. */
    public function shortRevision(): ?string
    {
        return (new BuildPresenter($this))->shortRevision();
    }

    /** @return string The saved release name or its compatible generated identifier. */
    public function releaseIdentifier(): string
    {
        return (new BuildPresenter($this))->releaseIdentifier();
    }

    /**
     * Find the preceding build using creation time and ID as a stable order.
     *
     * @return ?self The previous build in this repository, or null when none exists.
     */
    public function previousInRepository(): ?self
    {
        return $this->adjacentInRepository(false);
    }

    /**
     * Find the following build using creation time and ID as a stable order.
     *
     * @return ?self The next build in this repository, or null when none exists.
     */
    public function nextInRepository(): ?self
    {
        return $this->adjacentInRepository(true);
    }

    /**
     * Find the newest earlier successful build with recorded release metadata.
     *
     * @return ?self The eligible rollback candidate, or null when none exists.
     */
    public function latestRestorableBefore(): ?self
    {
        if (! $this->exists || ! $this->created_at) {
            return null;
        }

        return self::query()
            ->where('repository_id', $this->repository_id)
            ->where('status', self::STATUS_SUCCEEDED)
            ->whereNotNull('release_name')
            ->whereNotNull('release_path')
            ->where(function (Builder $query): void {
                $query->where('created_at', '<', $this->created_at)
                    ->orWhere(fn (Builder $query): Builder => $query
                        ->where('created_at', $this->created_at)
                        ->where('id', '<', $this->id));
            })
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    /**
     * Resolve an adjacent build without loading repository history into memory.
     *
     * @param  bool  $newer  Whether to find a newer build instead of an older one.
     * @return ?self The adjacent build, or null for an unsaved build or missing neighbor.
     */
    private function adjacentInRepository(bool $newer): ?self
    {
        if (! $this->exists || ! $this->created_at) {
            return null;
        }

        $operator = $newer ? '>' : '<';
        $direction = $newer ? 'asc' : 'desc';

        return self::query()
            ->where('repository_id', $this->repository_id)
            ->where(function (Builder $query) use ($operator): void {
                $query
                    ->where('created_at', $operator, $this->created_at)
                    ->orWhere(function (Builder $query) use ($operator): void {
                        $query
                            ->where('created_at', $this->created_at)
                            ->where('id', $operator, $this->id);
                    });
            })
            ->orderBy('created_at', $direction)
            ->orderBy('id', $direction)
            ->first();
    }
}
