<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A provider snapshot of a server taken before a risky change (updates, security fixes, runtime switches), so it can
 * be restored from the provider's dashboard if the change goes wrong.
 *
 * @property int $id
 * @property int $server_id
 * @property string $reason what was about to change
 * @property string|null $provider_snapshot the provider's reference to it
 * @property string $status taken, failed or deleted
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 */
final class ServerSnapshot extends Model
{
    /**
     * The attributes that can't be mass assigned: all of them; snapshots are written with forceFill.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * Get the server snapshotted.
     *
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
