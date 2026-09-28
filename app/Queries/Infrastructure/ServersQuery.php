<?php

declare(strict_types=1);

namespace App\Queries\Infrastructure;

use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;

final class ServersQuery
{
    /**
     * Get the account's servers by name, with their provider.
     *
     * @param  string  $accountId
     * @return Collection<int, Server>
     */
    public function handle(string $accountId): Collection
    {
        return Server::query()->where('account_id', $accountId)->with('provider')->orderBy('name')->orderBy('id')->get();
    }

    /**
     * Find one of the account's servers with its provider and creator; 404 otherwise.
     *
     * @param  string  $accountId
     * @param  string|int  $id
     * @return Server
     */
    public function find(string $accountId, int|string $id): Server
    {
        return Server::query()->where('account_id', $accountId)->with(['provider', 'creator'])->findOrFail((int) $id);
    }
}
