<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsAnnotation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class RemoveAnnotation
{
    /**
     * Remove a note from a site's chart.
     *
     * @param  User  $actor
     * @param  AnalyticsAnnotation  $annotation
     * @return void
     */
    public function handle(User $actor, AnalyticsAnnotation $annotation): void
    {
        Gate::forUser($actor)->authorize('update', $annotation->site);
        $annotation->delete();
    }
}
