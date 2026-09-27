<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BackupDestinationFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An S3-compatible bucket that holds the account's restic repositories (one per website, under the path prefix). The keys
 * and the repository password are encrypted; the password is generated and never shown.
 *
 * @property int $id
 * @property string $account_id
 * @property string|null $created_by
 * @property string $name
 * @property string $storage_provider a key of BackupDestinationPresets
 * @property string $endpoint
 * @property string $bucket
 * @property string $region
 * @property string $access_key
 * @property string $secret_key
 * @property string $repository_password
 * @property string $path_prefix
 * @property CarbonImmutable|null $last_verified_at
 * @property string|null $last_error
 * @property int|null $legacy_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Hidden(['access_key', 'secret_key', 'repository_password'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(BackupDestinationFactory::class)]
class BackupDestination extends Model
{
    /** @use HasFactory<BackupDestinationFactory> */
    use HasFactory;

    /**
     * Backup schedules that write here.
     *
     * @return HasMany<WebsiteBackupSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(WebsiteBackupSchedule::class);
    }

    /**
     * Backups stored here.
     *
     * @return HasMany<WebsiteBackup, $this>
     */
    public function backups(): HasMany
    {
        return $this->hasMany(WebsiteBackup::class);
    }

    /**
     * Encrypts the access key, secret key and restic repository password.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['access_key' => 'encrypted', 'secret_key' => 'encrypted', 'repository_password' => 'encrypted', 'last_verified_at' => 'immutable_datetime'];
    }
}
