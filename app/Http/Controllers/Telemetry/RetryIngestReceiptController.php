<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Models\IngestReceipt;
use App\Models\Project;
use App\Models\User;
use App\Services\Telemetry\TelemetryQueue;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

final class RetryIngestReceiptController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $receipt, TelemetryQueue $queue): RedirectResponse
    {
        Gate::authorize('manageService', [$project, 'monitoring']);
        $record = IngestReceipt::query()->whereIn('environment_id', $project->environments()->select('id'))->findOrFail($receipt);
        abort_unless($queue->retry($record->id), 409, __('Only failed deliveries with a kept payload can be retried.'));

        return to_route('monitoring.ingest.deliveries', [$project, $record->environment_id])
            ->with('status', __('Delivery queued again. Usage already counted isn’t counted twice.'));
    }
}
