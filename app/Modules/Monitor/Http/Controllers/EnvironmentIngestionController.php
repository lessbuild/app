<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Data\Telemetry\IngestStatus;
use App\Modules\Monitor\Http\Requests\SearchIngestReceiptsRequest;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\IngestReceipt;
use App\Modules\Monitor\Services\Telemetry\TelemetryQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class EnvironmentIngestionController extends Controller
{
    public function index(SearchIngestReceiptsRequest $request, Application $application, Environment $environment): Response
    {
        $environment->setRelation('application', $application);
        Gate::authorize('view', $environment);
        $filters = $request->validated();
        $receipts = $environment->ingestReceipts()
            ->select([
                'id', 'environment_id', 'source', 'status', 'accepted_count', 'duplicate_count',
                'attempt_count', 'processing_attempts', 'recovery_count', 'received_at', 'processed_at',
                'failed_at', 'next_attempt_at', 'last_error_code',
            ])
            ->withExists('ingestPayload as payload_available')
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->latest('received_at')->latest('id')->paginate(25, ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->appends(array_intersect_key($filters, ['status' => true]));

        return response()->view('monitor::environments.ingestion', [
            'application' => $application,
            'environment' => $environment,
            'receipts' => $receipts,
            'filters' => $filters,
            'statuses' => IngestStatus::cases(),
            'totals' => $environment->ingestReceipts()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status'),
            'canRetry' => Gate::allows('update', $environment),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(Application $application, Environment $environment, IngestReceipt $ingestReceipt, TelemetryQueue $queue): RedirectResponse
    {
        Gate::authorize('update', $environment);
        abort_unless($queue->retry($ingestReceipt->id), 409, 'Only failed deliveries with a retained payload can be retried.');

        return to_route('monitor.environments.ingestion', [$application, $environment])
            ->with('status', 'Delivery queued for another processing attempt. Previously recorded usage will not be charged again.');
    }
}
