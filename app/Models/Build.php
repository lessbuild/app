<?php

namespace App\Models;

use App\Models\Concerns\HasDuration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Build extends Model
{
    use HasDuration, HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_DEPLOYING = 'deploying';

    public const STATUS_RUNNING = 'running';

    public const STATUS_TIMING_OUT = 'timing_out';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_WEBHOOK = 'webhook';

    public const TRIGGER_REDEPLOY = 'redeploy';

    public const TRIGGER_ROLLBACK = 'rollback';

    public const TRIGGER_SCHEDULED = 'scheduled';

    public const TRIGGER_API = 'api';

    public const TRIGGER_PROMOTION = 'promotion';

    public const ACTIVE_STATUSES = [
        self::STATUS_AWAITING_APPROVAL,
        self::STATUS_QUEUED,
        self::STATUS_DEPLOYING,
        self::STATUS_RUNNING,
        self::STATUS_TIMING_OUT,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
        self::STATUS_CANCELED,
        self::STATUS_REJECTED,
    ];

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

    public function logs(): MorphMany
    {
        return $this->morphMany(Log::class, 'parentable');
    }

    public function events(): MorphMany
    {
        return $this->morphMany(Event::class, 'parentable');
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function redeployedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'redeployed_from_build_id');
    }

    public function rolledBackFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rolled_back_from_build_id');
    }

    public function automaticRollbackBuild(): BelongsTo
    {
        return $this->belongsTo(self::class, 'automatic_rollback_build_id');
    }

    public function promotedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'promoted_from_build_id');
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(self::class, 'promoted_from_build_id');
    }

    public function shortRevision(): ?string
    {
        return $this->revision ? substr($this->revision, 0, 12) : null;
    }

    public function releaseIdentifier(): string
    {
        return $this->release_name
            ?? sprintf('%s-build-%d', ($this->created_at ?? now())->utc()->format('YmdHis'), $this->getKey());
    }

    public function previousInRepository(): ?self
    {
        return $this->adjacentInRepository(false);
    }

    public function nextInRepository(): ?self
    {
        return $this->adjacentInRepository(true);
    }

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
                    ->orWhere(fn (Builder $query) => $query
                        ->where('created_at', $this->created_at)
                        ->where('id', '<', $this->id));
            })
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

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
