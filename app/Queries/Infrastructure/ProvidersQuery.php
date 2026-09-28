<?php

declare(strict_types=1);

namespace App\Queries\Infrastructure;

use App\Enums\ProviderType;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Collection;

final class ProvidersQuery
{
    /**
     * Get the account's providers with how many servers each has.
     *
     * @param  string  $accountId
     * @return Collection<int, Provider>
     */
    public function handle(string $accountId): Collection
    {
        return Provider::query()->where('account_id', $accountId)->withCount('servers')->orderBy('name')->orderBy('id')->get();
    }

    /**
     * Get the account's providers that can create servers, for the server form.
     *
     * @param  string  $accountId
     * @return Collection<int, Provider>
     */
    public function serverHosts(string $accountId): Collection
    {
        return Provider::query()->where('account_id', $accountId)->whereIn('type', array_map(fn (ProviderType $type): string => $type->value, ProviderType::serverHosts()))
            ->orderBy('name')->orderBy('id')->get();
    }

    /**
     * Find one of the account's providers; 404 otherwise.
     *
     * @param  string  $accountId
     * @param  string|int  $id
     * @return Provider
     */
    public function find(string $accountId, int|string $id): Provider
    {
        return Provider::query()->where('account_id', $accountId)->findOrFail((int) $id);
    }
}
