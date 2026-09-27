<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The latest copy of one of a server's logs (provisioning, and later system logs).
 *
 * @property int $id
 * @property int $server_id
 * @property string $type
 * @property string $status queued, refreshing, ready or failed
 * @property string|null $log
 * @property string|null $error
 * @property CarbonImmutable|null $refreshed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class ServerLogSnapshot extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_REFRESHING = 'refreshing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $guarded = ['id'];

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['refreshed_at' => 'immutable_datetime'];
    }
}
