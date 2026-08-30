<?php

namespace App\Models;

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

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_WEBHOOK = 'webhook';

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

    public function shortRevision(): ?string
    {
        return $this->revision ? substr($this->revision, 0, 12) : null;
    }
}
