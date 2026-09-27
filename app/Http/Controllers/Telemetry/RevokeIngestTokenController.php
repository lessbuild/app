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
    public function __invoke(#[CurrentUser] User $user, Project $project, string $token, RevokeIngestToken $revoke): RedirectResponse
    {
        $record = IngestToken::query()->whereIn('environment_id', $project->environments()->select('id'))->findOrFail((int) $token);
        $revoke->handle($user, $record);

        return to_route('monitoring.setup', $project)->with('status', __('Key revoked. Requests that use it are refused.'));
    }
}
