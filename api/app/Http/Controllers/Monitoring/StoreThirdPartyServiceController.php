<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\FollowThirdPartyService;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StoreThirdPartyServiceController
{
    /**
     * Follow a third-party service's status and return to the monitors.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  FollowThirdPartyService  $follow
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, FollowThirdPartyService $follow): JsonResponse
    {
        $data = $request->validate(['provider' => ['required', 'string', 'max:40'], 'name' => ['nullable', 'string', 'max:80'], 'url' => ['nullable', 'string', 'max:255']]);
        $service = $follow->handle($user, $project, $data['provider'], $data['name'] ?? null, $data['url'] ?? null);

        return response()->json(['redirect' => route('monitoring.monitors', $project, false), 'message' => __('Following :name.', ['name' => $service->name])]);
    }
}
