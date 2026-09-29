<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Repository;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class UpdateBuildCache
{
    /**
     * Turn the repository's build cache on or off, and optionally clear it: the next deploy then starts with an empty
     * cache and removes the old one from the server.
     *
     * @param  User  $actor
     * @param  Repository  $repository
     * @param  bool  $enabled
     * @param  bool  $clear
     * @return void
     */
    public function handle(User $actor, Repository $repository, bool $enabled, bool $clear = false): void
    {
        Gate::forUser($actor)->authorize('update', $repository);
        $repository->forceFill([
            'build_cache_enabled' => $enabled,
            'build_cache_version' => $repository->build_cache_version + ($clear ? 1 : 0),
        ])->save();
    }
}
