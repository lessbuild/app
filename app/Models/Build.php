<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Build extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_DEPLOYING = 'deploying';

    public const STATUS_RUNNING = 'running';

    public const STATUS_TIMING_OUT = 'timing_out';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_WEBHOOK = 'webhook';

    public const TRIGGER_REDEPLOY = 'redeploy';

    public const ACTIVE_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_DEPLOYING,
        self::STATUS_RUNNING,
        self::STATUS_TIMING_OUT,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
        self::STATUS_CANCELED,
    ];

    public const DEPLOYMENT_LOG_TYPE = 'deployment';

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    protected $casts = [
        'built_at' => 'datetime',
        'remote_process_id' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
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

    public function redeployedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'redeployed_from_build_id');
    }

    public function shortRevision(): ?string
    {
        return $this->revision ? substr($this->revision, 0, 12) : null;
    }

    public function durationSeconds(): ?int
    {
        if (! $this->started_at || ! $this->finished_at || $this->finished_at->isBefore($this->started_at)) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at);
    }

    public function durationLabel(): ?string
    {
        $seconds = $this->durationSeconds();

        if ($seconds === null) {
            return null;
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;
        $parts = [];

        if ($hours > 0) {
            $parts[] = "{$hours}h";
        }
        if ($minutes > 0) {
            $parts[] = "{$minutes}m";
        }
        if ($remainingSeconds > 0 || $parts === []) {
            $parts[] = "{$remainingSeconds}s";
        }

        return implode(' ', $parts);
    }

    public function previousInRepository(): ?self
    {
        return $this->adjacentInRepository(false);
    }

    public function nextInRepository(): ?self
    {
        return $this->adjacentInRepository(true);
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
