<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Jobs\Infrastructure\ManageDatabaseUser;
use App\Models\DatabaseUser;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class RemoveDatabaseUser
{
    /**
     * Drop the login from MySQL, then forget it. Also used when a user expires (no actor). Works on any plan.
     *
     * @param  DatabaseUser  $user
     * @param  User|null  $actor
     * @return void
     */
    public function handle(DatabaseUser $user, ?User $actor = null): void
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('update', $user->website);
        }
        if (DatabaseUser::query()->whereKey($user->id)->where('status', '!=', 'removing')->update(['status' => 'removing', 'error' => null]) === 1) {
            ManageDatabaseUser::dispatch($user->id, 'remove');
        }
    }
}
