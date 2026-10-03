<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsFunnel;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DeleteFunnel
{
    /**
     * Delete a funnel (its report is worked out from events, so nothing else goes with it).
     *
     * @param  User  $actor
     * @param  AnalyticsFunnel  $funnel
     * @return void
     */
    public function handle(User $actor, AnalyticsFunnel $funnel): void
    {
        Gate::forUser($actor)->authorize('update', $funnel->site);
        $funnel->delete();
    }
}
