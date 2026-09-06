<?php

namespace App\Models;

use App\Enums\ServerCommandStatus;
use App\Models\Concerns\HasDuration;
use App\Models\Scopes\ServerCommandExecutionScopes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ServerCommandExecution extends Model
{
    use HasDuration;
    use ServerCommandExecutionScopes;

    public const STATUS_QUEUED = ServerCommandStatus::Queued->value;

    public const STATUS_RUNNING = ServerCommandStatus::Running->value;

    public const STATUS_SUCCEEDED = ServerCommandStatus::Succeeded->value;

    public const STATUS_FAILED = ServerCommandStatus::Failed->value;

    public const STATUS_CANCELED = ServerCommandStatus::Canceled->value;

    public const ACTIVE_STATUSES = ServerCommandStatus::ACTIVE_VALUES;

    public const TERMINAL_STATUSES = ServerCommandStatus::TERMINAL_VALUES;

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

    /**
     * Interpret the stored status without changing its serialized string representation.
     *
     * @return ServerCommandStatus|null The known lifecycle state, or null for absent or legacy values.
     */
    public function statusEnum(): ?ServerCommandStatus
    {
        $status = $this->getAttribute('status');

        return $status instanceof ServerCommandStatus
            ? $status
            : (is_string($status) ? ServerCommandStatus::tryFrom($status) : null);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ServerCommandExecution, $this> */
    public function rerunFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rerun_from_execution_id');
    }

    /** @return HasMany<ServerCommandExecution, $this> */
    public function reruns(): HasMany
    {
        return $this->hasMany(self::class, 'rerun_from_execution_id');
    }

    /** @return MorphMany<Event, $this> */
    public function events(): MorphMany
    {
        return $this->morphMany(Event::class, 'parentable');
    }
}
