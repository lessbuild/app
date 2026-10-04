<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Actions\Telemetry\RevokeIngestToken;
use App\Models\IngestToken;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class RevokeIngestTokenController
{
    /**
     * Revoke an ingest key.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  IngestToken  $token
     * @param  RevokeIngestToken  $revoke
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, IngestToken $token, RevokeIngestToken $revoke): JsonResponse
    {
        $revoke->handle($user, $token);

        return response()->json(['redirect' => route('monitoring.setup', $project, false), 'message' => __('Key revoked. Requests that use it are refused.')]);
    }
}
