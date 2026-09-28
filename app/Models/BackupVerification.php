<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Proof a snapshot restores: it's restored into a temporary directory and database on the same server (never over the live
 * site), checked, smoke-tested with `php artisan migrate:status`, then removed.
 *
 * @property int $id
 * @property int $website_backup_id
 * @property string|null $requested_by
 * @property string $snapshot_id
 * @property string $status queued, running, succeeded or failed
 * @property string $integrity_status pending, passed or failed
 * @property string $smoke_status pending, passed or failed
 * @property string $cleanup_status pending, passed or failed
 * @property string|null $failure_stage preflight, restore, integrity, smoke or cleanup
 * @property int|null $duration_seconds
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WebsiteBackup $backup
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class BackupVerification extends Model
{
    public const STAGES = ['preflight', 'restore', 'integrity', 'smoke', 'cleanup'];

    /**
     * Get the backup being checked.
     *
     * @return BelongsTo<WebsiteBackup, $this>
     */
    public function backup(): BelongsTo
    {
        return $this->belongsTo(WebsiteBackup::class, 'website_backup_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * Plain columns; dates come back as Carbon.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['duration_seconds' => 'integer', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }
}
