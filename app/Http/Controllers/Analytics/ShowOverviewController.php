<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Models\Project;
use Illuminate\Http\RedirectResponse;

/** The Analytics landing page; the reporting dashboard replaces this redirect in the next slice. */
final class ShowOverviewController
{
    public function __invoke(Project $project): RedirectResponse
    {
        return to_route('analytics.sites', $project);
    }
}
