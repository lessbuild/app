<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Enums\IngestStatus;
use App\Http\Requests\Telemetry\SearchIngestReceiptsRequest;
use App\Models\IngestReceipt;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

/** An environment's recent ingest deliveries and their processing status. */
final class ShowIngestReceiptsController
{
    /**
     * An environment's recent deliveries, optionally by status.
     */
    public function __invoke(SearchIngestReceiptsRequest $request, #[CurrentUser] User $user, Project $project, string $environment, ProjectOverviewQuery $overview): View
    {
        $target = $project->environments()->findOrFail($environment);
        $filters = $request->validated();
        $status = is_string($filters['status'] ?? null) ? $filters['status'] : null;

        return view('telemetry.deliveries', [
            'overview' => $overview->handle($project, $user),
            'environment' => $target,
            'receipts' => IngestReceipt::query()->where('environment_id', $target->id)->withExists('ingestPayload as payload_available')
                ->when($status !== null, fn ($query) => $query->where('status', $status))
                ->latest('received_at')->latest('id')->paginate(25, ['*'], 'page', (int) ($filters['page'] ?? 1))->withQueryString(),
            'status' => $status,
            'statuses' => IngestStatus::cases(),
            'canRetry' => $user->can('manageService', [$project, 'monitoring']),
        ]);
    }
}
