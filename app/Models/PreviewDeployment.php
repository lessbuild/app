<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreviewDeployment extends Model
{
    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_DEPLOYING = 'deploying';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CLOSED = 'closed';

    protected $guarded = [];

    protected $casts = [
        'pull_request_number' => 'integer',
        'last_activity_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function sourceRepository(): BelongsTo
    {
        return $this->belongsTo(Repository::class, 'source_repository_id');
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class)->withTrashed();
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class)->withTrashed();
    }
}
