<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Exceptions\StateConflict;
use App\Models\Build;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DeleteRepository
{
    /** Stop deploying from a repository. Its builds stay in the history; the website and its releases aren't touched. */
    public function handle(User $actor, Repository $repository): void
    {
        Gate::forUser($actor)->authorize('delete', $repository);
        StateConflict::unless(! $repository->builds()->whereIn('status', Build::ACTIVE)->exists(), __('Wait for the running deploy to finish.'));
        $repository->forceFill(['webhook_enabled' => false])->save();
        $repository->delete();
    }
}
