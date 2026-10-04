<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveStatusPage;
use App\Http\Requests\Monitoring\StatusPageRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class StoreStatusPageController
{
    /**
     * Create a status page.
     *
     * @param  StatusPageRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  SaveStatusPage  $save
     * @return JsonResponse
     */
    public function __invoke(StatusPageRequest $request, #[CurrentUser] User $user, Project $project, SaveStatusPage $save): JsonResponse
    {
        $page = $save->handle($project->account, $user, $request->validated());

        return response()->json(['redirect' => route('monitoring.status-pages.show', [$project, $page->id], false), 'message' => __('Status page saved.')]);
    }
}
