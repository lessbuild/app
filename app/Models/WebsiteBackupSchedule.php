<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Backs a website up to one destination every day or week at a UTC time, keeping the latest `retention_count` snapshots.
 *
 * @property int $id
 * @property int $website_id
 * @property int $backup_destination_id
 * @property string $frequency daily or weekly
 * @property int|null $weekday 0 (Sunday) to 6, for weekly schedules
 * @property string $run_at HH:MM in UTC
 * @property int $retention_count
 * @property CarbonImmutable|null $last_queued_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Website $website
 * @property-read BackupDestination $destination
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class WebsiteBackupSchedule extends Model
{
    /**
     * Whether a backup should be queued now: past today's time, not yet queued since, and on the right day.
     *
     * @param  CarbonImmutable  $now
     * @return bool
     */
    public function isDue(CarbonImmutable $now): bool
    {
        $scheduled = $now->setTimeFromTimeString($this->run_at);
        if ($now->lt($scheduled) || ($this->last_queued_at !== null && $this->last_queued_at->gte($scheduled))) {
            return false;
        }

        return $this->frequency === 'daily' || $now->dayOfWeek === $this->weekday;
    }

    /**
     * The website backed up.
     *
     * @return BelongsTo<Website, $this>
     */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /**
     * Where the backups go.
     *
     * @return BelongsTo<BackupDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class, 'backup_destination_id');
    }

    /**
     * Plain columns; dates come back as Carbon.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['weekday' => 'integer', 'retention_count' => 'integer', 'last_queued_at' => 'immutable_datetime'];
    }
}
