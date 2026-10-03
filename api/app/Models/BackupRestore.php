<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Putting a snapshot back over the live website. The script keeps a safety copy and rolls back if any step fails.
 *
 * @property int $id
 * @property int $website_backup_id
 * @property string|null $requested_by
 * @property string $status queued, running, succeeded or failed
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WebsiteBackup $backup
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class BackupRestore extends Model
{
    /**
     * Get the backup being restored.
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
        return ['started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }
}
