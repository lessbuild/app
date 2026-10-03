<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\SaveSite;
use App\Http\Requests\Analytics\SiteRequest;
use App\Models\Project;
use App\Models\User;
use App\Support\SetupGuideReturn;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreSiteController
{
    /**
     * Add an analytics site and shows its tracking snippet.
     *
     * @param  SiteRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  SaveSite  $save
     * @return RedirectResponse
     */
    public function __invoke(SiteRequest $request, #[CurrentUser] User $user, Project $project, SaveSite $save): RedirectResponse
    {
        $site = $save->handle($user, $project, $request->toDetails());

        if (($guide = SetupGuideReturn::from($request)) !== null) {

            return redirect($guide)->with('status', __('Site added. Paste its snippet into your pages to finish this step.'));

        }

        return to_route('analytics.sites.show', [$project, $site->id])->with('status', __('Site added. Paste the snippet below into your pages.'));
    }
}
