<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\ArchiveAlertDestination;
use App\Models\AlertDestination;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ArchiveAlertDestinationController
{
    /**
     * Archive a destination, if it hasn't changed since the page was opened.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AlertDestination  $destination
     * @param  ArchiveAlertDestination  $archive
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AlertDestination $destination, ArchiveAlertDestination $archive): JsonResponse
    {
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:0']])['version'];
        $target = $archive->handle($project->account, $user, $destination, $version);

        return response()->json(['redirect' => route('monitoring.destinations', $project, false), 'message' => __(':destination was archived.', ['destination' => $target->name])]);
    }
}
