<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A threshold on a server metric (one server, or every server in the account). After enough readings in a row past the
 * threshold, account owners and admins are told; again when it recovers. Cooldown stops repeats.
 *
 * @property int $id
 * @property string $account_id
 * @property string|null $created_by
 * @property int|null $server_id
 * @property string $name
 * @property string $metric
 * @property string $operator gte or lte
 * @property float $threshold
 * @property int $consecutive_breaches
 * @property int $breach_count
 * @property int $cooldown_minutes
 * @property bool $is_enabled
 * @property bool $is_alerting
 * @property CarbonImmutable|null $last_evaluated_at
 * @property CarbonImmutable|null $last_triggered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server|null $server
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class ServerAlertRule extends Model
{
    public const METRICS = ['cpu_percent' => 'CPU (%)', 'memory_percent' => 'Memory (%)', 'disk_percent' => 'Disk (%)', 'load_1m' => 'Load (1 min)', 'process_count' => 'Processes'];

    /**
     * The server the rule watches; null for rules that watch every server.
     *
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Plain columns; dates come back as Carbon.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'threshold' => 'float', 'consecutive_breaches' => 'integer', 'breach_count' => 'integer', 'cooldown_minutes' => 'integer',
            'is_enabled' => 'boolean', 'is_alerting' => 'boolean', 'last_evaluated_at' => 'immutable_datetime', 'last_triggered_at' => 'immutable_datetime',
        ];
    }
}
