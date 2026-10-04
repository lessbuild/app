<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\DeleteStatusPage;
use App\Models\Project;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteStatusPageController
{
    /**
     * Delete a status page.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  StatusPage  $page
     * @param  DeleteStatusPage  $delete
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, StatusPage $page, DeleteStatusPage $delete): JsonResponse
    {
        $delete->handle($project->account, $user, $page);

        return response()->json(['redirect' => route('monitoring.status-pages', $project, false), 'message' => __('Status page deleted.')]);
    }
}
