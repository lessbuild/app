<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Repository, $this> */
    public function sourceRepository(): BelongsTo
    {
        return $this->belongsTo(Repository::class, 'source_repository_id');
    }

    /** @return BelongsTo<Environment, $this> */
    public function sourceEnvironment(): BelongsTo
    {
        return $this->belongsTo(Environment::class, 'source_environment_id');
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return BelongsTo<Website, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class)->withTrashed();
    }

    /** @return BelongsTo<Repository, $this> */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class)->withTrashed();
    }

    /** @return HasMany<PreviewSecretApproval, $this> */
    public function secretApprovals(): HasMany
    {
        return $this->hasMany(PreviewSecretApproval::class);
    }

    /** @return HasMany<PreviewStackCleanup, $this> */
    public function stackCleanups(): HasMany
    {
        return $this->hasMany(PreviewStackCleanup::class);
    }
}
