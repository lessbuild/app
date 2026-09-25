<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Data\Telemetry\IssuedIngestToken;
use App\Modules\Monitor\Http\Requests\StoreEnvironmentRequest;
use App\Modules\Monitor\Http\Resources\EnvironmentConnectionResource;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Services\ArchiveEnvironment;
use App\Modules\Monitor\Services\Core\RestoreMonitorResource;
use App\Modules\Monitor\Services\CreateIngestToken;
use App\Modules\Monitor\Services\SuspendHeartbeats;
use App\Modules\Monitor\Services\SuspendQueueMonitors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EnvironmentController extends Controller
{
    public function store(StoreEnvironmentRequest $request, Application $application, CreateIngestToken $createToken): RedirectResponse
    {
        $issued = DB::connection('monitor')->transaction(function () use ($request, $application, $createToken): IssuedIngestToken {
            $application = Application::query()->lockForUpdate()->findOrFail($application->id);
            Gate::authorize('update', $application);
            $environment = $application->environments()->create($request->validated());
            $application->increment('lifecycle_revision');

            return $createToken->create($environment, $request->user(), 'Initial collector');
        });

        return to_route('monitor.environments.show', [$application, $issued->token->environment_id])
            ->with('status', 'Environment created. Copy your new token now; it will not be shown again.')
            ->with('issued_ingest_token', ['environment_id' => $issued->token->environment_id, 'encrypted_secret' => Crypt::encryptString($issued->secret)]);
    }

    public function show(Request $request, Application $application, Environment $environment, RestoreMonitorResource $restore): Response
    {
        Gate::authorize('viewRetained', $environment);
        $canManage = Gate::allows('update', $environment);
        $issued = $request->session()->get('issued_ingest_token');
        $secret = null;

        if ($canManage && is_array($issued) && ($issued['environment_id'] ?? null) === $environment->id) {
            $secret = Crypt::decryptString($request->session()->pull('issued_ingest_token')['encrypted_secret']);
        }

        return response()->view('monitor::environments.show', [
            'application' => $application,
            'environment' => $environment,
            'canManage' => $canManage,
            'canRestore' => Gate::allows('restore', $environment),
            'canInteract' => Gate::allows('view', $environment),
            ...$restore->viewData($request->user(), $environment),
            'tokens' => $canManage ? $environment->ingestTokens()->with('creator:id,name')->latest('id')->paginate(10, ['*'], 'tokens_page') : collect(),
            'secret' => $secret,
            'recentReceipts' => $environment->ingestReceipts()
                ->select(['id', 'source', 'status', 'event_count', 'accepted_count', 'duplicate_count', 'attempt_count', 'received_at'])
                ->latest('received_at')->latest('id')->limit(6)->get(),
            'recentEvents' => $environment->telemetryEvents()
                ->select(['id', 'type', 'name', 'service', 'trace_id', 'occurred_at', 'created_at'])
                ->latest('id')->limit(5)->get(),
            'samplePayload' => json_encode([
                'batch_id' => 'connection-'.now()->format('YmdHis'),
                'events' => [[
                    'id' => 'first-event', 'type' => 'log', 'name' => 'Connection test',
                    'service' => $application->slug, 'severity' => 'info',
                ]],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function update(StoreEnvironmentRequest $request, Application $application, Environment $environment, SuspendHeartbeats $heartbeats, SuspendQueueMonitors $queues): RedirectResponse
    {
        DB::connection('monitor')->transaction(function () use ($request, $application, $environment, $heartbeats, $queues): void {
            $application = Application::query()->lockForUpdate()->findOrFail($application->id);
            $environment = Environment::query()->lockForUpdate()->findOrFail($environment->id);
            Gate::authorize('update', $environment);
            $environment->fill($request->validated());
            if ($environment->isDirty('status') && $environment->status !== 'active') {
                $heartbeats->environment($environment->id);
                $queues->environment($environment->id);
            }
            $lifecycleChanged = $environment->isDirty('status');
            $environment->save();
            if ($lifecycleChanged) {
                $application->increment('lifecycle_revision');
            }
        }, attempts: 3);

        return to_route('monitor.environments.show', [$application, $environment])->with('status', 'Environment updated.');
    }

    public function destroy(Request $request, Application $application, Environment $environment, ArchiveEnvironment $archive): RedirectResponse
    {
        Gate::authorize('delete', $environment);
        $request->validate(['confirmation' => ['required', Rule::in([$environment->name])]]);
        $archive->archive($environment, $request->user());

        return to_route('monitor.applications.show', $application)->with('status', 'Environment archived and tokens revoked. Its telemetry is preserved.');
    }

    public function restore(Request $request, Application $application, Environment $environment, RestoreMonitorResource $restore): RedirectResponse
    {
        $request->validate(['idempotency_key' => ['nullable', 'uuid']]);
        $pending = $restore->restore($request->user(), $environment, $request->input('idempotency_key') ?? (string) Str::uuid());
        if ($pending !== null) {
            return to_route('platform.resource-restorations.show', $pending);
        }

        return to_route('monitor.environments.show', [$application, $environment])->with('status', 'Environment restored. Generate a new token to resume ingestion.');
    }

    public function connection(Application $application, Environment $environment): JsonResponse
    {
        Gate::authorize('view', $environment);
        $environment->loadCount(['ingestTokens as active_token_count' => fn ($query) => $query->active()]);

        return (new EnvironmentConnectionResource($environment))->response()->header('Cache-Control', 'no-store, private');
    }
}
