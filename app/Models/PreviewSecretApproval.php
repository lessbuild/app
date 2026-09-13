<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreviewSecretApproval extends Model
{
    /** Preview-owned values that an approval may never replace. */
    public const PROTECTED_KEYS = [
        'APP_ENV',
        'APP_DEBUG',
        'APP_KEY',
        'APP_URL',
        'BUILDPUSHER_PREVIEW',
        'DB_CONNECTION',
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',
    ];

    protected $guarded = [];

    protected $casts = [
        'variable_versions' => 'array',
        'approved_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** @return BelongsTo<PreviewDeployment, $this> */
    public function preview(): BelongsTo
    {
        return $this->belongsTo(PreviewDeployment::class, 'preview_deployment_id');
    }

    /** @return BelongsTo<Environment, $this> */
    public function sourceEnvironment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
