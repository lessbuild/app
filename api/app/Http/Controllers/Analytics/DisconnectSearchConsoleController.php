<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\DisconnectSearchConsole;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DisconnectSearchConsoleController
{
    /**
     * Unlink a site from Google Search Console.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  DisconnectSearchConsole  $disconnect
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, AnalyticsSite $site, DisconnectSearchConsole $disconnect): RedirectResponse
    {
        $disconnect->handle($user, $site);

        return to_route('analytics.sites.show', [$project, $site])->with('status', __('Search Console is disconnected.'));
    }
}
