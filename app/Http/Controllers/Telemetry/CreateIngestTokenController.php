<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Actions\Telemetry\CreateIngestToken;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

final class CreateIngestTokenController
{
    /**
     * Creates an ingest key for one of the project's environments, optionally expiring, and shows it once.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  string  $environment
     * @param  CreateIngestToken  $create
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, string $environment, CreateIngestToken $create): RedirectResponse
    {
        $target = $project->environments()->findOrFail($environment);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'expires_in_days' => ['nullable', 'integer', 'between:1,365']]);
        $days = $data['expires_in_days'] ?? null;
        $issued = $create->handle($user, $target, (string) $data['name'], is_numeric($days) ? now()->addDays((int) $days) : null);

        return to_route('monitoring.setup', $project)->with('status', __('Ingest key created. Copy it now; only its hash is kept.'))
            ->with('issued_ingest_key', ['environment_id' => $target->id, 'secret' => Crypt::encryptString($issued->secret)]);
    }
}
