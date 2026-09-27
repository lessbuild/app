<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Actions\Telemetry\RevokeIngestToken;
use App\Models\IngestToken;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RevokeIngestTokenController
{
    /**
     * Revokes an ingest key.
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, IngestToken $token, RevokeIngestToken $revoke): RedirectResponse
    {
        $revoke->handle($user, $token);

        return to_route('monitoring.setup', $project)->with('status', __('Key revoked. Requests that use it are refused.'));
    }
}
