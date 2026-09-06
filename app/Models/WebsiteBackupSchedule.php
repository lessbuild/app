<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebsiteBackupSchedule extends Model
{
    protected $guarded = [];

    protected $casts = [
        'weekday' => 'integer',
        'retention_count' => 'integer',
        'is_active' => 'boolean',
        'last_queued_at' => 'datetime',
    ];

    /** @return BelongsTo<Website, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /** @return BelongsTo<BackupDestination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class, 'backup_destination_id');
    }

    /** @return HasMany<WebsiteBackup, $this> */
    public function backups(): HasMany
    {
        return $this->hasMany(WebsiteBackup::class);
    }
}
