<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Actions\Security\QueueSecurityScan;
use App\Models\Project;
use App\Models\User;
use App\Services\Security\Scanners;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StoreSecurityScanController
{
    /**
     * Run one of the project's Security checks now (or, with "all", every check the plan includes), and send the app
     * back to the overview.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  QueueSecurityScan  $queue
     * @param  Scanners  $scanners
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, QueueSecurityScan $queue, Scanners $scanners): JsonResponse
    {
        $request->validate(['kind' => ['required', 'string', 'max:24']]);
        $kind = $request->string('kind')->toString();
        // "all" runs every check the plan includes.
        $kinds = $kind === 'all'
            ? array_keys(array_filter($scanners->all(), fn ($scanner): bool => $scanners->included($project->account, $scanner)))
            : [$kind];
        foreach ($kinds as $each) {
            $queue->handle($project, (string) $each, $user);
        }

        return response()->json(['redirect' => route('security.overview', $project, false), 'message' => __('Scanning. Results appear here in a minute or two.')]);
    }
}
