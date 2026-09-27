<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A Caddy reverse proxy on one server that spreads a hostname's traffic over application servers (its nodes), skipping
 * nodes that fail the health path. Belongs to the account, like servers.
 *
 * @property int $id
 * @property string $account_id
 * @property string|null $created_by
 * @property int $server_id the server running the proxy
 * @property int|null $website_id the website it fronts, if any
 * @property string $hostname
 * @property string $health_path
 * @property string $status pending, active, failed, removing or removal_failed
 * @property string|null $last_error
 * @property CarbonImmutable|null $applied_at
 * @property int|null $legacy_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 * @property-read Website|null $website
 * @property-read \Illuminate\Database\Eloquent\Collection<int, LoadBalancerNode> $nodes
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class LoadBalancer extends Model
{
    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return BelongsTo<Website, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /** @return HasMany<LoadBalancerNode, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(LoadBalancerNode::class);
    }

    public function isRemoving(): bool
    {
        return in_array($this->status, ['removing', 'removal_failed'], true);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['applied_at' => 'immutable_datetime'];
    }
}
