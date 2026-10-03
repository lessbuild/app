<?php

declare(strict_types=1);

namespace App\Queries\Infrastructure;

use App\Enums\ServerType;
use App\Models\Account;
use App\Models\Environment;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Database\Eloquent\Collection;

final class WebsitesQuery
{
    /**
     * Get the account's websites by name, with their server.
     *
     * @param  string  $accountId
     * @return Collection<int, Website>
     */
    public function handle(string $accountId): Collection
    {
        return Website::query()->where('account_id', $accountId)->with('server')->orderBy('name')->orderBy('id')->get();
    }

    /**
     * Find one of the account's websites with its server, domains, environment and health monitor; 404 otherwise.
     *
     * @param  string  $accountId
     * @param  string|int  $id
     * @return Website
     */
    public function find(string $accountId, int|string $id): Website
    {
        return Website::query()->where('account_id', $accountId)->with(['server', 'domains.dnsProvider', 'environment.project', 'healthMonitor'])->findOrFail((int) $id);
    }

    /**
     * Get the account's environments grouped by project, for linking a website to one.
     *
     * @param  Account  $account
     * @return Collection<int, Environment>
     */
    public function environments(Account $account): Collection
    {
        return Environment::query()->forAccount($account)->with('project')->orderBy('project_id')->orderBy('name')->get();
    }

    /**
     * Get the servers a website can be created on: active app servers with a MySQL root password to create its
     * database with.
     *
     * @param  string  $accountId
     * @return Collection<int, Server>
     */
    public function hosts(string $accountId): Collection
    {
        return Server::query()->where('account_id', $accountId)->where('provisioning_status', Server::STATUS_ACTIVE)->where('type', ServerType::App)
            ->whereNotNull('mysql_root_password')->orderBy('name')->get();
    }
}
