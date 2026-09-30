<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Models\Monitor;
use App\Models\ThirdPartyService;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class UnfollowThirdPartyService
{
    /**
     * Stop following a service's status.
     *
     * @param  User  $actor
     * @param  ThirdPartyService  $service
     * @return void
     */
    public function handle(User $actor, ThirdPartyService $service): void
    {
        Gate::forUser($actor)->authorize('create', [Monitor::class, $service->project]);
        $service->delete();
    }
}
