<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Actions\Telemetry\SetBrowserErrorTracking;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdateBrowserErrorsController
{
    /**
     * Turn an environment's browser error tracking on (with its origins) or off, and return to the setup page.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  SetBrowserErrorTracking  $set
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, SetBrowserErrorTracking $set): JsonResponse
    {
        abort_unless($environment->project_id === $project->id, 404);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'origins' => ['nullable', 'string', 'max:2000']]);
        $origins = preg_split('/[\s,]+/', (string) ($data['origins'] ?? '')) ?: [];
        $set->handle($user, $environment, (bool) $data['enabled'], $origins);

        return response()->json(['redirect' => route('monitoring.setup', [$project, 'environment' => $environment->id], false).'#'.'browser-errors', 'message' => $data['enabled'] ? __('Browser errors are on for :environment.', ['environment' => $environment->name]) : __('Browser errors are off for :environment.', ['environment' => $environment->name])]);
    }
}
