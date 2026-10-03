<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsExperiment;
use App\Models\AnalyticsSite;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class StopExperiment
{
    /**
     * Stop an experiment, freezing its results at this moment, or delete it outright.
     *
     * @param  User  $actor
     * @param  AnalyticsExperiment  $experiment
     * @param  bool  $delete
     * @return void
     */
    public function handle(User $actor, AnalyticsExperiment $experiment, bool $delete): void
    {
        Gate::forUser($actor)->authorize('update', AnalyticsSite::query()->findOrFail($experiment->site_id));
        if ($delete) {
            $experiment->delete();

            return;
        }
        $experiment->forceFill(['status' => 'stopped', 'stopped_at' => now()])->save();
    }
}
