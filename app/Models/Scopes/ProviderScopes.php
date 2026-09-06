<?php

namespace App\Models\Scopes;

use App\Models\Provider;
use Illuminate\Database\Eloquent\Builder;

/**
 * @phpstan-require-extends Provider
 */
trait ProviderScopes
{
    /**
     * Limit the query to infrastructure provider types.
     *
     * @param  Builder<Provider>  $query  The query to constrain.
     * @return Builder<Provider> The same query with the scope applied.
     */
    public function scopeForServers(Builder $query): Builder
    {
        return $query->whereIn('provider', self::SERVER_TYPES);
    }

    /**
     * Limit the query to source control provider types.
     *
     * @param  Builder<Provider>  $query  The query to constrain.
     * @return Builder<Provider> The same query with the scope applied.
     */
    public function scopeForRepositories(Builder $query): Builder
    {
        return $query->whereIn('provider', self::SOURCE_CONTROL_TYPES);
    }

    /**
     * Limit the query to providers with servers, repositories, or DNS domains.
     *
     * @param  Builder<Provider>  $query  The query to constrain.
     * @return Builder<Provider> The same query with the scope applied.
     */
    public function scopeInUse(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereHas('servers')->orWhereHas('repositories')->orWhereHas('domains');
        });
    }

    /**
     * Limit the query to providers without servers, repositories, or DNS domains.
     *
     * @param  Builder<Provider>  $query  The query to constrain.
     * @return Builder<Provider> The same query with the scope applied.
     */
    public function scopeUnused(Builder $query): Builder
    {
        return $query->whereDoesntHave('servers')->whereDoesntHave('repositories')->whereDoesntHave('domains');
    }

    /**
     * Limit the query by connection health, treating a null state as unchecked.
     *
     * @param  Builder<Provider>  $query  The query to constrain.
     * @param  string  $status  The connection health state to match.
     * @return Builder<Provider> The same query with the scope applied.
     */
    public function scopeConnectionState(Builder $query, string $status): Builder
    {
        return $status === self::CONNECTION_UNCHECKED
            ? $query->whereNull('connection_status')
            : $query->where('connection_status', $status);
    }
}
