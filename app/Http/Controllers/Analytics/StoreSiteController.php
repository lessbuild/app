<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\SaveSite;
use App\Http\Requests\Analytics\SiteRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreSiteController
{
    public function __invoke(SiteRequest $request, #[CurrentUser] User $user, Project $project, SaveSite $save): RedirectResponse
    {
        $site = $save->handle($user, $project, $request->toDetails());

        return to_route('analytics.sites.show', [$project, $site->id])->with('status', __('Site added. Paste the snippet below into your pages.'));
    }
}
