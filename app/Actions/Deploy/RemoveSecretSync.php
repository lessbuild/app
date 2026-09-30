<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\EnvironmentVariable;
use App\Models\SecretSync;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class RemoveSecretSync
{
    /**
     * Disconnect a password manager. Its variables stay, as ordinary secrets, so the next deploy doesn't lose them.
     *
     * @param  User  $actor
     * @param  SecretSync  $sync
     * @return void
     */
    public function handle(User $actor, SecretSync $sync): void
    {
        Gate::forUser($actor)->authorize('configureDeploy', $sync->environment);
        EnvironmentVariable::query()->where('secret_sync_id', $sync->id)->update(['secret_sync_id' => null]);
        $sync->delete();
    }
}
