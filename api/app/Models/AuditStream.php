<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Where an account's audit log entries are sent as they happen: a Slack channel, a signed webhook, or JSON objects in
 * a backup destination's bucket. Paused after 20 failures in a row.
 *
 * @property int $id
 * @property string $account_id
 * @property string $name
 * @property string $type slack, webhook or s3
 * @property string|null $endpoint_url encrypted; Slack and webhooks
 * @property string|null $signing_secret encrypted; webhooks
 * @property int|null $backup_destination_id S3
 * @property bool $enabled
 * @property int $failure_count
 * @property string|null $last_error
 * @property Carbon|null $last_delivered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BackupDestination|null $destination
 */
class AuditStream extends Model
{
    public const MAX_FAILURES = 20;

    public const TYPES = ['slack' => 'Slack', 'webhook' => 'Signed webhook', 's3' => 'S3-compatible storage'];

    /**
     * Get the backup destination S3 streams write to.
     *
     * @return BelongsTo<BackupDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class, 'backup_destination_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * Encrypts the endpoint and secret.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['endpoint_url' => 'encrypted', 'signing_secret' => 'encrypted', 'enabled' => 'boolean', 'last_delivered_at' => 'datetime'];
    }
}
