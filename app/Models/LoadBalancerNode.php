<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoadBalancerNode extends Model
{
    protected $guarded = [];

    protected $casts = ['is_enabled' => 'boolean', 'upstream_port' => 'integer', 'weight' => 'integer', 'last_checked_at' => 'datetime'];

    /** @return BelongsTo<LoadBalancer, $this> */
    public function loadBalancer(): BelongsTo
    {
        return $this->belongsTo(LoadBalancer::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
