<?php

namespace App\Modules\Deployer\Models;

use App\Modules\Deployer\Database\DeployerModel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerMetric extends DeployerModel
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'load_1m' => 'float',
        'load_5m' => 'float',
        'load_15m' => 'float',
        'cpu_percent' => 'integer',
        'memory_percent' => 'integer',
        'disk_percent' => 'integer',
        'network_rx_bytes' => 'integer',
        'network_tx_bytes' => 'integer',
        'disk_read_bytes' => 'integer',
        'disk_write_bytes' => 'integer',
        'process_count' => 'integer',
        'uptime_seconds' => 'integer',
        'recorded_at' => 'datetime',
    ];

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
