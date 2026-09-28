<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One restic snapshot of a website's database dump, .env and shared storage.
 *
 * @property int $id
 * @property int $website_id
 * @property int $backup_destination_id
 * @property int|null $website_backup_schedule_id
 * @property string|null $triggered_by
 * @property string $status queued, running, succeeded or failed
 * @property string|null $snapshot_id
 * @property int|null $size_bytes
 * @property CarbonImmutable|null $https_verified_at when the snapshot was sent over HTTPS
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Website $website
 * @property-read BackupDestination $destination
 * @property-read WebsiteBackupSchedule|null $schedule
 * @property-read \Illuminate\Database\Eloquent\Collection<int, BackupRestore> $restores
 * @property-read \Illuminate\Database\Eloquent\Collection<int, BackupVerification> $verifications
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class WebsiteBackup extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    /**
     * Determine whether the backup completed with a snapshot restic can restore.
     *
     * @return bool
     */
    public function isRestorable(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED && preg_match('/\A[a-f0-9]{8,64}\z/D', (string) $this->snapshot_id) === 1;
    }

    /**
     * Get the website backed up, including deleted ones.
     *
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class)->withTrashed();
    }

    /**
     * Get the destination the backup is stored in.
     *
     * @return BelongsTo<BackupDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class, 'backup_destination_id');
    }

    /**
     * Get the schedule that made it, if any.
     *
     * @return BelongsTo<WebsiteBackupSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(WebsiteBackupSchedule::class, 'website_backup_schedule_id');
    }

    /**
     * Get the restores made from this backup.
     *
     * @return HasMany<BackupRestore, $this>
     */
    public function restores(): HasMany
    {
        return $this->hasMany(BackupRestore::class);
    }

    /**
     * Get the checks that this backup can be restored.
     *
     * @return HasMany<BackupVerification, $this>
     */
    public function verifications(): HasMany
    {
        return $this->hasMany(BackupVerification::class);
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
        return ['size_bytes' => 'integer', 'https_verified_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }
}
