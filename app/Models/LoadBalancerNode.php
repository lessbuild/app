<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A server a load balancer sends traffic to, on a port, with a weight (1–10: its share of requests).
 *
 * @property int $id
 * @property int $load_balancer_id
 * @property int $server_id
 * @property int $upstream_port
 * @property int $weight
 * @property bool $is_enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read LoadBalancer $loadBalancer
 * @property-read Server $server
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class LoadBalancerNode extends Model
{
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['upstream_port' => 'integer', 'weight' => 'integer', 'is_enabled' => 'boolean'];
    }
}
