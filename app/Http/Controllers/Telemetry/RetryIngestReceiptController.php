<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Models\IngestReceipt;
use App\Models\Project;
use App\Models\User;
use App\Services\Telemetry\TelemetryQueue;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RetryIngestReceiptController
{
    /**
     * Queues a failed delivery again from its kept payload (409 when there's nothing to retry). Usage already counted
     * isn't counted twice.
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, IngestReceipt $receipt, TelemetryQueue $queue): RedirectResponse
    {
        abort_unless($queue->retry($receipt->id), 409, __('Only failed deliveries with a kept payload can be retried.'));

        return to_route('monitoring.ingest.deliveries', [$project, $receipt->environment_id])
            ->with('status', __('Delivery queued again. Usage already counted isn’t counted twice.'));
    }
}
