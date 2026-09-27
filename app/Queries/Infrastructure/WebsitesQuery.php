<?php

declare(strict_types=1);

namespace App\Queries\Infrastructure;

use App\Enums\ServerType;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Database\Eloquent\Collection;

final class WebsitesQuery
{
    /** @return Collection<int, Website> */
    public function handle(string $accountId): Collection
    {
        return Website::query()->where('account_id', $accountId)->with('server')->orderBy('name')->orderBy('id')->get();
    }

    public function find(string $accountId, int|string $id): Website
    {
        return Website::query()->where('account_id', $accountId)->with(['server', 'domains.dnsProvider'])->findOrFail((int) $id);
    }

    /** @return Collection<int, Server> */
    public function hosts(string $accountId): Collection
    {
        return Server::query()->where('account_id', $accountId)->where('provisioning_status', Server::STATUS_ACTIVE)->where('type', ServerType::App)
            ->whereNotNull('mysql_root_password')->orderBy('name')->get();
    }
}
