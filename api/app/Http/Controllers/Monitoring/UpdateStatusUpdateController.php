<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveStatusUpdate;
use App\Http\Requests\Monitoring\StatusUpdateRequest;
use App\Models\Project;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class UpdateStatusUpdateController
{
    /**
     * Edit a posted update.
     *
     * @param  StatusUpdateRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  StatusPage  $page
     * @param  string  $update
     * @param  SaveStatusUpdate  $save
     * @return JsonResponse
     */
    public function __invoke(StatusUpdateRequest $request, #[CurrentUser] User $user, Project $project, StatusPage $page, string $update, SaveStatusUpdate $save): JsonResponse
    {
        $save->handle($page, $user, $request->validated(), $page->updates()->findOrFail((int) $update));

        return response()->json(['redirect' => route('monitoring.status-pages.show', [$project, $page->id], false), 'message' => __('Update saved.')]);
    }
}
