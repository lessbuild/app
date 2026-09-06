<?php

namespace App\Models\Scopes;

use App\Models\Server;
use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;

/**
 * @phpstan-require-extends Website
 */
trait WebsiteScopes
{
    /**
     * Limit the query to provisioned websites on active servers.
     *
     * @param  Builder<Website>  $query  The query to constrain.
     * @return Builder<Website> The same query with the scope applied.
     */
    public function scopeReadyForDeployments(Builder $query): Builder
    {
        return $query
            ->where('provisioning_status', self::STATUS_ACTIVE)
            ->whereHas('server', fn (Builder $query): Builder => $query
                ->where('provisioning_status', Server::STATUS_ACTIVE));
    }

    /**
     * Limit the query to failed provisioning or enabled checks reporting unhealthy websites.
     *
     * @param  Builder<Website>  $query  The query to constrain.
     * @return Builder<Website> The same query with the scope applied.
     */
    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->where('provisioning_status', self::STATUS_FAILED)
                ->orWhere(function (Builder $query): void {
                    $query
                        ->where('health_check_enabled', true)
                        ->where('health_status', self::HEALTH_UNHEALTHY);
                });
        });
    }
}
