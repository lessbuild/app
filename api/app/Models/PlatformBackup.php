<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One backup of the platform's own database: a compressed file kept locally for a few days and, when off-site
 * storage is set up, copied to it.
 *
 * @property int $id
 * @property string $file the file name, e.g. platform-20261004-023000.sqlite.gz
 * @property string $driver sqlite or pgsql
 * @property string $status succeeded or failed
 * @property int|null $size bytes
 * @property string|null $sha256 of the compressed file
 * @property string|null $remote_key where the off-site copy is, once uploaded
 * @property string|null $error why it failed
 * @property string $trigger schedule or manual
 * @property CarbonImmutable|null $uploaded_at
 * @property CarbonImmutable|null $local_deleted_at
 * @property CarbonImmutable|null $remote_deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class PlatformBackup extends Model
{
    /**
     * Determine whether the backup finished.
     *
     * @return bool
     */
    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }

    /**
     * Determine whether a copy is off-site.
     *
     * @return bool
     */
    public function isOffsite(): bool
    {
        return $this->uploaded_at !== null && $this->remote_deleted_at === null;
    }

    /**
     * Determine whether the local copy is still on disk.
     *
     * @return bool
     */
    public function isLocal(): bool
    {
        return $this->succeeded() && $this->local_deleted_at === null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * Reads the size as a number and the upload and deletion times as immutable dates.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size' => 'integer', 'uploaded_at' => 'immutable_datetime', 'local_deleted_at' => 'immutable_datetime', 'remote_deleted_at' => 'immutable_datetime'];
    }
}
