<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A server's resource use at one moment, collected over SSH every five minutes and kept for 30 days.
 *
 * @property int $id
 * @property int $server_id
 * @property float $load_1m
 * @property float $load_5m
 * @property float $load_15m
 * @property int $cpu_percent
 * @property int $memory_percent
 * @property int $disk_percent
 * @property int $network_rx_bytes
 * @property int $network_tx_bytes
 * @property int $disk_read_bytes
 * @property int $disk_write_bytes
 * @property int $process_count
 * @property int $uptime_seconds
 * @property CarbonImmutable $recorded_at
 * @property-read Server $server
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class ServerMetric extends Model
{
    /**
     * Each sample records its own `recorded_at`; rows are never updated.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Only the key is guarded: samples are written by the collector, never from request input.
     *
     * @var array<string>
     */
    protected $guarded = ['id'];

    /**
     * The server that was sampled.
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
            'load_1m' => 'float', 'load_5m' => 'float', 'load_15m' => 'float', 'cpu_percent' => 'integer', 'memory_percent' => 'integer',
            'disk_percent' => 'integer', 'network_rx_bytes' => 'integer', 'network_tx_bytes' => 'integer', 'disk_read_bytes' => 'integer',
            'disk_write_bytes' => 'integer', 'process_count' => 'integer', 'uptime_seconds' => 'integer', 'recorded_at' => 'immutable_datetime',
        ];
    }
}
