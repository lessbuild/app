<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Http\Requests\StoreIngestTokenRequest;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\IngestToken;
use App\Modules\Monitor\Services\CreateIngestToken;
use App\Modules\Monitor\Services\RevokeIngestToken;
use App\Modules\Monitor\Services\RotateIngestToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;

class IngestTokenController extends Controller
{
    public function store(StoreIngestTokenRequest $request, Application $application, Environment $environment, CreateIngestToken $createToken): RedirectResponse
    {
        $expiresAt = $request->filled('expires_in_days') ? now()->addDays($request->integer('expires_in_days')) : null;
        $issued = $createToken->create($environment, $request->user(), $request->validated('name'), $expiresAt);

        return to_route('monitor.environments.show', [$application, $environment])
            ->with('status', 'Token created. Copy it now; only its hash is stored.')
            ->with('issued_ingest_token', ['environment_id' => $environment->id, 'encrypted_secret' => Crypt::encryptString($issued->secret)]);
    }

    public function rotate(Request $request, Application $application, Environment $environment, IngestToken $ingestToken, RotateIngestToken $rotateToken): RedirectResponse
    {
        Gate::authorize('update', $environment);
        $issued = $rotateToken->rotate($ingestToken, $request->user());

        return to_route('monitor.environments.show', [$application, $environment])
            ->with('status', 'Token rotated. The old token no longer works; update your collector now.')
            ->with('issued_ingest_token', ['environment_id' => $environment->id, 'encrypted_secret' => Crypt::encryptString($issued->secret)]);
    }

    public function destroy(Application $application, Environment $environment, IngestToken $ingestToken, RevokeIngestToken $revokeToken): RedirectResponse
    {
        Gate::authorize('update', $environment);
        $revokeToken->revoke($ingestToken, request()->user(), $environment, $application);

        return to_route('monitor.environments.show', [$application, $environment])->with('status', 'Token revoked. Requests using this token will be rejected.');
    }
}
