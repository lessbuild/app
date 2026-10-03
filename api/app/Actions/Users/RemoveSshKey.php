<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Jobs\Security\SyncSshAccess;
use App\Models\User;
use App\Models\UserSshKey;
use Illuminate\Auth\Access\AuthorizationException;

final class RemoveSshKey
{
    /**
     * Remove one of your SSH keys; it's taken off every server you have access to.
     *
     * @param  User  $user
     * @param  UserSshKey  $key
     * @return void
     */
    public function handle(User $user, UserSshKey $key): void
    {
        if ($key->user_id !== $user->id) {
            throw new AuthorizationException;
        }
        $key->delete();
        SyncSshAccess::everywhere($user);
    }
}
