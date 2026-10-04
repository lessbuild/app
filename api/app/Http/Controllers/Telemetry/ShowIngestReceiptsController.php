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
use Illuminate\Http\JsonResponse;

final class ShowIngestReceiptsController
{
    /**
     * List the batches of events an environment sent (`?status=`, `?page=`), and whether each was processed.
     *
     * @param  SearchIngestReceiptsRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  string  $environment
     * @param  ProjectOverviewQuery  $overview
     * @return JsonResponse
     */
    public function __invoke(SearchIngestReceiptsRequest $request, #[CurrentUser] User $user, Project $project, string $environment, ProjectOverviewQuery $overview): JsonResponse
    {
        $target = $project->environments()->findOrFail($environment);
        $filters = $request->validated();
        $status = is_string($filters['status'] ?? null) ? $filters['status'] : null;
        $receipts = IngestReceipt::query()->where('environment_id', $target->id)->withExists('ingestPayload as payload_available')
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->latest('received_at')->latest('id')->paginate(25, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'environment' => ['id' => $target->id, 'name' => $target->name],
            'receipts' => collect($receipts->items())->map(fn (IngestReceipt $receipt): array => [
                'id' => $receipt->id,
                'receivedAt' => $receipt->received_at->toIso8601String(),
                'source' => $receipt->source->value,
                'status' => $receipt->status->value,
                'statusLabel' => $receipt->status->label(),
                'statusTone' => $receipt->status->tone(),
                'error' => $receipt->processingError() === null ? null : __($receipt->processingError()),
                'accepted' => $receipt->accepted_count,
                'duplicates' => $receipt->duplicate_count,
                'retryable' => $receipt->status === IngestStatus::Failed && (bool) $receipt->getAttribute('payload_available'),
            ])->values(),
            'page' => $receipts->currentPage(),
            'lastPage' => $receipts->lastPage(),
            'status' => $status,
            'statuses' => array_map(fn (IngestStatus $option): array => ['value' => $option->value, 'label' => $option->label()], IngestStatus::cases()),
            'canRetry' => $user->can('manageService', [$project, 'monitoring']),
        ]);
    }
}
