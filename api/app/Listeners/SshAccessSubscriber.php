<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\Accounts\MemberRemoved;
use App\Jobs\Security\SyncSshAccess;
use App\Models\Server;
use App\Models\ServerSshGrant;
use Illuminate\Events\Dispatcher;

/** Takes a member's SSH access off the account's servers when they leave or are removed. */
final class SshAccessSubscriber
{
    /**
     * Register the listener.
     *
     * @param  Dispatcher  $events
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [MemberRemoved::class => 'removed'];
    }

    /**
     * Mark the person's grants on the account's servers for removal and queue the key removals.
     *
     * @param  MemberRemoved  $event
     * @return void
     */
    public function removed(MemberRemoved $event): void
    {
        ServerSshGrant::query()->where('user_id', $event->member->id)->whereIn('server_id', Server::query()->where('account_id', $event->account->id)->select('id'))->get()
            ->each(function (ServerSshGrant $grant): void {
                $grant->forceFill(['status' => 'removing'])->save();
                SyncSshAccess::dispatch($grant->server_id, $grant->user_id);
            });
    }
}
