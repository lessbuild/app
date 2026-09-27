<?php

declare(strict_types=1);

namespace App\Queries\Infrastructure;

use App\Enums\ProviderType;
use App\Models\Provider;
use Illuminate\Database\Eloquent\Collection;

final class ProvidersQuery
{
    /**
     * The account's providers with how many servers each has.
     *
     * @return Collection<int, Provider>
     */
    public function handle(string $accountId): Collection
    {
        return Provider::query()->where('account_id', $accountId)->withCount('servers')->orderBy('name')->orderBy('id')->get();
    }

    /**
     * The account's providers that can create servers, for the server form.
     *
     * @return Collection<int, Provider>
     */
    public function serverHosts(string $accountId): Collection
    {
        return Provider::query()->where('account_id', $accountId)->whereIn('type', array_map(fn (ProviderType $type): string => $type->value, ProviderType::serverHosts()))
            ->orderBy('name')->orderBy('id')->get();
    }

    /**
     * One of the account's providers; 404 otherwise.
     */
    public function find(string $accountId, int|string $id): Provider
    {
        return Provider::query()->where('account_id', $accountId)->findOrFail((int) $id);
    }
}
