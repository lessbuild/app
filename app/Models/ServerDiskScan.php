<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A server's latest disk scan: how much space each kind of clearable file takes.
 *
 * @property int $id
 * @property int $server_id
 * @property string $status queued, running, ready or failed
 * @property array<string, array{bytes: int, items: int}>|null $findings by category (see DiskCleanup::CATEGORIES), plus disk totals
 * @property string|null $error
 * @property string|null $last_cleaned the category last cleared
 * @property Carbon|null $scanned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 */
final class ServerDiskScan extends Model
{
    /**
     * The attributes that can't be mass assigned: all of them; scans are written with forceFill.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * Get the attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['findings' => 'array', 'scanned_at' => 'datetime'];
    }

    /**
     * Get the server scanned.
     *
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
