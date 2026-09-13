<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupRestoreVerification extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const CHECK_PENDING = 'pending';

    public const CHECK_PASSED = 'passed';

    public const CHECK_FAILED = 'failed';

    public const CLEANUP_PENDING = 'pending';

    public const CLEANUP_PASSED = 'passed';

    public const CLEANUP_FAILED = 'failed';

    public const TARGET_SAME_SERVER_TEMPORARY = 'same_server_temporary';

    public const OVERWRITE_NEVER = 'never';

    public const STAGE_PREFLIGHT = 'preflight';

    public const STAGE_RESTORE = 'restore';

    public const STAGE_INTEGRITY = 'integrity';

    public const STAGE_SMOKE = 'smoke';

    public const STAGE_CLEANUP = 'cleanup';

    protected $guarded = [];

    protected $hidden = ['error', 'snapshot_id'];

    protected $casts = [
        'duration_seconds' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /** @return BelongsTo<WebsiteBackup, $this> */
    public function backup(): BelongsTo
    {
        return $this->belongsTo(WebsiteBackup::class, 'website_backup_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
