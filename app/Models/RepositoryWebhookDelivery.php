<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepositoryWebhookDelivery extends Model
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PENDING = 'pending';

    public const STATUS_UNAVAILABLE = 'unavailable';

    protected $guarded = [];

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }
}
