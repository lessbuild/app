<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerLogSnapshot extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_REFRESHING = 'refreshing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_REFRESHING,
        self::STATUS_READY,
        self::STATUS_FAILED,
    ];

    protected $guarded = [];

    protected $casts = [
        'refreshed_at' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
