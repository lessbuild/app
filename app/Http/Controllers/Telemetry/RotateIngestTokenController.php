<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Actions\Telemetry\RotateIngestToken;
use App\Models\IngestToken;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Crypt;

final class RotateIngestTokenController
{
    /**
     * Replaces an ingest key. The new secret rides to the setup page encrypted in the flash, so it's shown once and
     * never stored in plain text in the session.
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, IngestToken $token, RotateIngestToken $rotate): RedirectResponse
    {
        $issued = $rotate->handle($user, $token);

        return to_route('monitoring.setup', $project)->with('status', __('Key replaced. The old key no longer works; update your collector now.'))
            ->with('issued_ingest_key', ['environment_id' => $token->environment_id, 'secret' => Crypt::encryptString($issued->secret)]);
    }
}
