<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\ClearAdSpend;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ClearAdSpendController
{
    /**
     * Remove a site's imported ad spend (from one source, when given) and return to the campaigns page.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  ClearAdSpend  $clear
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AnalyticsSite $site, ClearAdSpend $clear): RedirectResponse
    {
        $source = $request->input('source');
        $clear->handle($user, $site, is_string($source) && $source !== '' ? $source : null);

        return to_route('analytics.campaigns', [$project, 'site' => $site->id])->with('status', __('Ad spend removed.'));
    }
}
