<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Http\Requests\Telemetry\IntegrationSetupRequest;
use App\Models\IngestToken;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Telemetry\CollectionHealthQuery;
use App\Support\Telemetry\IntegrationSetupGuide;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Crypt;

/** Connect an app: ingest keys per environment, collection health and code for each stack. */
final class ShowTelemetrySetupController
{
    public function __invoke(IntegrationSetupRequest $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, CollectionHealthQuery $health, IntegrationSetupGuide $guide): View
    {
        $stack = $request->stack();
        $issued = session('issued_ingest_key');
        try {
            $issuedKey = is_array($issued) && is_string($issued['secret'] ?? null) ? ['environment_id' => (string) $issued['environment_id'], 'secret' => Crypt::decryptString($issued['secret'])] : null;
        } catch (DecryptException) {
            $issuedKey = null;
        }

        return view('telemetry.setup', [
            'overview' => $overview->handle($project, $user),
            'health' => $health->handle($project),
            'tokens' => IngestToken::query()->whereIn('environment_id', $project->environments()->select('id'))
                ->whereNull('revoked_at')->latest('id')->get()->groupBy('environment_id'),
            'issuedKey' => $issuedKey,
            'stack' => $stack,
            'stackOptions' => $guide->stacks(),
            'guide' => $guide->for($stack, route('api.ingest'), route('api.ingest.receipts.show', 'RECEIPT_ID')),
            'otlp' => $guide->openTelemetryConfiguration(route('api.otlp', 'traces'), route('api.otlp', 'logs'), route('api.otlp', 'metrics')),
            'canManage' => $user->can('manageService', [$project, 'monitoring']),
        ]);
    }
}
