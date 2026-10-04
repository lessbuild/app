<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RetryWebsiteProvisioning;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class RetryWebsiteProvisioningController
{
    /**
     * Try a failed website setup again.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Website  $website
     * @param  RetryWebsiteProvisioning  $retry
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Website $website, RetryWebsiteProvisioning $retry): JsonResponse
    {
        $retried = $retry->handle($project->account, $user, $website);

        return response()->json(['redirect' => route('infrastructure.websites.show', [$project, $website->id], false), 'message' => $retried ? __('Trying again.') : __('Nothing is waiting for a retry.')]);
    }
}
