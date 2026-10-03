<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Enums\ServerType;
use App\Models\Account;
use App\Models\Server;
use Illuminate\Validation\ValidationException;

/** Finds an account's server that can host websites: active, an app server, with MySQL set up. */
final class WebsiteServers
{
    /**
     * Find the account's server with this ID if it can host websites; otherwise throw a validation error on
     * `server_id`.
     *
     * @param  Account  $account
     * @param  int  $serverId
     * @return Server
     */
    public function handle(Account $account, int $serverId): Server
    {
        $server = Server::query()->where('account_id', $account->id)->whereKey($serverId)->where('provisioning_status', Server::STATUS_ACTIVE)
            ->where('type', ServerType::App)->whereNotNull('mysql_root_password')->first();

        return $server ?? throw ValidationException::withMessages(['server_id' => __('Choose an active app server with MySQL set up.')]);
    }
}
