<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Actions\Telemetry\RotateIngestToken;
use App\Models\IngestToken;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class RotateIngestTokenController
{
    /**
     * Replace an ingest key. The new secret rides to the setup page encrypted in the flash, so it's shown once and
     * never stored in plain text in the session.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  IngestToken  $token
     * @param  RotateIngestToken  $rotate
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, IngestToken $token, RotateIngestToken $rotate): JsonResponse
    {
        $issued = $rotate->handle($user, $token);

        // The new key is shown once, on the setup page; only its hash is kept.
        return response()->json([
            'redirect' => route('monitoring.setup', $project, false),
            'message' => __('Key replaced. The old key no longer works; update your collector now.'),
            'secrets' => ['ingest_key' => $issued->secret, 'ingest_environment_id' => $token->environment_id],
        ]);
    }
}
