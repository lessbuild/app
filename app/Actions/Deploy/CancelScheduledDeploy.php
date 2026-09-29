<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Repository;
use App\Models\ScheduledDeploy;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class CancelScheduledDeploy
{
    /**
     * Cancel a booked deploy of the repository that hasn't run yet; 404 when there's no such pending one.
     *
     * @param  User  $actor
     * @param  Repository  $repository
     * @param  int  $scheduledId
     * @return void
     */
    public function handle(User $actor, Repository $repository, int $scheduledId): void
    {
        Gate::forUser($actor)->authorize('deploy', $repository);
        ScheduledDeploy::query()->where('repository_id', $repository->id)->whereKey($scheduledId)->where('status', ScheduledDeploy::STATUS_PENDING)
            ->firstOrFail()->forceFill(['status' => 'cancelled', 'result' => __('Cancelled by :name.', ['name' => $actor->name])])->save();
    }
}
