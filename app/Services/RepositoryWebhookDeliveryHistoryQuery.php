<?php

namespace App\Services;

use App\Models\Repository;
use App\Models\RepositoryWebhookDelivery;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RepositoryWebhookDeliveryHistoryQuery
{
    /**
     * Build a repository-scoped webhook delivery query using the accepted history filters.
     *
     * @param  array{delivery_status: ?string, delivery_date_from: ?string, delivery_date_to: ?string}  $filters
     * @return HasMany<RepositoryWebhookDelivery, Repository> The filtered delivery relationship.
     */
    public function for(Repository $repository, array $filters): HasMany
    {
        return $repository->webhookDeliveries()
            ->when($filters['delivery_status'], fn ($query, string $status) => $query
                ->where('status', $status))
            ->when($filters['delivery_date_from'], fn ($query, string $date) => $query
                ->whereDate('created_at', '>=', $date))
            ->when($filters['delivery_date_to'], fn ($query, string $date) => $query
                ->whereDate('created_at', '<=', $date));
    }

    /**
     * Summarize delivery states for the selected repository and history filters.
     *
     * @param  array{delivery_status: ?string, delivery_date_from: ?string, delivery_date_to: ?string}  $filters
     * @return array{total: int, queued: int, pending: int, skipped: int, unavailable: int, superseded: int, received: int}
     */
    public function metrics(Repository $repository, array $filters): array
    {
        $counts = $this->for($repository, $filters)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count);

        return [
            'total' => $counts->sum(),
            'queued' => $counts->get(RepositoryWebhookDelivery::STATUS_QUEUED, 0),
            'pending' => $counts->get(RepositoryWebhookDelivery::STATUS_PENDING, 0),
            'skipped' => $counts->get(RepositoryWebhookDelivery::STATUS_SKIPPED, 0),
            'unavailable' => $counts->get(RepositoryWebhookDelivery::STATUS_UNAVAILABLE, 0),
            'superseded' => $counts->get(RepositoryWebhookDelivery::STATUS_SUPERSEDED, 0),
            'received' => $counts->get(RepositoryWebhookDelivery::STATUS_RECEIVED, 0),
        ];
    }
}
