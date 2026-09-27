<?php

declare(strict_types=1);

namespace App\Queries\Infrastructure;

use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;

final class ServersQuery
{
    /**
     * The account's servers by name, with their provider.
     *
     * @return Collection<int, Server>
     */
    public function handle(string $accountId): Collection
    {
        return Server::query()->where('account_id', $accountId)->with('provider')->orderBy('name')->orderBy('id')->get();
    }

    /**
     * One of the account's servers with its provider and creator; 404 otherwise.
     */
    public function find(string $accountId, int|string $id): Server
    {
        return Server::query()->where('account_id', $accountId)->with(['provider', 'creator'])->findOrFail((int) $id);
    }
}
