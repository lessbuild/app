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
    public function __invoke(#[CurrentUser] User $user, Project $project, string $token, RotateIngestToken $rotate): RedirectResponse
    {
        $record = IngestToken::query()->whereIn('environment_id', $project->environments()->select('id'))->findOrFail((int) $token);
        $issued = $rotate->handle($user, $record);

        return to_route('monitoring.setup', $project)->with('status', __('Key replaced. The old key no longer works; update your collector now.'))
            ->with('issued_ingest_key', ['environment_id' => $record->environment_id, 'secret' => Crypt::encryptString($issued->secret)]);
    }
}
