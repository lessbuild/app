<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A database server's continuous backup: base backups and the log kept in a backup destination for a number of days,
 * so the database can be restored to any moment since it was turned on (within the retention).
 *
 * @property int $id
 * @property int $server_id
 * @property int $backup_destination_id
 * @property int $retention_days
 * @property Carbon $enabled_at
 * @property int|null $setup_execution_id the command that set it up on the server
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 * @property-read BackupDestination $destination
 * @property-read ServerCommandExecution|null $setupExecution
 */
class DatabaseBackupPlan extends Model
{
    /**
     * Get the database server.
     *
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Get where the backups go.
     *
     * @return BelongsTo<BackupDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class, 'backup_destination_id');
    }

    /**
     * Get the command that set it up.
     *
     * @return BelongsTo<ServerCommandExecution, $this>
     */
    public function setupExecution(): BelongsTo
    {
        return $this->belongsTo(ServerCommandExecution::class, 'setup_execution_id');
    }

    /**
     * Get the earliest moment the database can be restored to: when backup was turned on, or the start of the
     * retention window.
     *
     * @return Carbon
     */
    public function earliestRestore(): Carbon
    {
        return $this->enabled_at->max(now()->subDays($this->retention_days));
    }

    /**
     * Get the attributes that should be cast.
     *
     * Reads when it was turned on as a date.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['enabled_at' => 'datetime', 'retention_days' => 'integer'];
    }
}
