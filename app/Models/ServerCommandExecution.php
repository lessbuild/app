<?php

namespace App\Models;

use App\Models\Concerns\HasDuration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ServerCommandExecution extends Model
{
    use HasDuration;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    public const ACTIVE_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_SUCCEEDED,
        self::STATUS_FAILED,
        self::STATUS_CANCELED,
    ];

    public const STATUSES = [
        ...self::ACTIVE_STATUSES,
        ...self::TERMINAL_STATUSES,
    ];

    protected $guarded = [];

    protected $hidden = ['command', 'output'];

    protected $casts = [
        'command' => 'encrypted',
        'output' => 'encrypted',
        'exit_code' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rerunFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rerun_from_execution_id');
    }

    public function reruns(): HasMany
    {
        return $this->hasMany(self::class, 'rerun_from_execution_id');
    }

    public function events(): MorphMany
    {
        return $this->morphMany(Event::class, 'parentable');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }
}
