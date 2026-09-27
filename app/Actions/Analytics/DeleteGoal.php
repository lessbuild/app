<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsGoal;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DeleteGoal
{
    public function __construct(private readonly RebuildSiteReports $rebuild) {}

    public function handle(User $actor, AnalyticsGoal $goal): void
    {
        $site = $goal->site;
        Gate::forUser($actor)->authorize('update', $site);

        $goal->delete();
        $this->rebuild->handle($site);
    }
}
