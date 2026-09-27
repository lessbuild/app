<?php

declare(strict_types=1);

namespace App\Queries\Infrastructure;

use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;

final class ServersQuery
{
    /** @return Collection<int, Server> */
    public function handle(string $accountId): Collection
    {
        return Server::query()->where('account_id', $accountId)->with('provider')->orderBy('name')->orderBy('id')->get();
    }

    public function find(string $accountId, int|string $id): Server
    {
        return Server::query()->where('account_id', $accountId)->with(['provider', 'creator'])->findOrFail((int) $id);
    }
}
