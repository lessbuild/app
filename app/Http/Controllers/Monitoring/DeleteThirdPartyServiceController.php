<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\UnfollowThirdPartyService;
use App\Models\Project;
use App\Models\ThirdPartyService;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteThirdPartyServiceController
{
    /**
     * Stop following a third-party service and return to the monitors. Another project's services are a 404.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  int  $service
     * @param  UnfollowThirdPartyService  $unfollow
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, int $service, UnfollowThirdPartyService $unfollow): RedirectResponse
    {
        $unfollow->handle($user, ThirdPartyService::query()->where('project_id', $project->id)->findOrFail($service));

        return to_route('monitoring.monitors', $project)->with('status', __('Stopped following it.'));
    }
}
