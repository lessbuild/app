<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\DeleteSite;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteSiteController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, AnalyticsSite $site, DeleteSite $delete): RedirectResponse
    {
        $delete->handle($user, $site);

        return to_route('analytics.sites', $project)->with('status', __(':site and its data were deleted.', ['site' => $site->name]));
    }
}
