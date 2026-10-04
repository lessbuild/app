<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Actions\Security\QueueSecurityScan;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StoreSecurityScanController
{
    /**
     * Run one of the project's Security checks now, and send the app back to the overview.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  QueueSecurityScan  $queue
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, QueueSecurityScan $queue): JsonResponse
    {
        $request->validate(['kind' => ['required', 'string', 'max:24']]);
        $queue->handle($project, $request->string('kind')->toString(), $user);

        return response()->json(['redirect' => route('security.overview', $project, false), 'message' => __('Scanning. Results appear here in a minute or two.')]);
    }
}
