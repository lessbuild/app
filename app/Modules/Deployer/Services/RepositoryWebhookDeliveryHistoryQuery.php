<?php

namespace App\Modules\Deployer\Services;

use App\Core\Services\Auth\ProductAuthentication;
use App\Modules\Deployer\Models\Repository;
use App\Modules\Deployer\Models\RepositoryWebhookDelivery;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Core\DeployerResourceProjection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;

class RepositoryWebhookDeliveryHistoryQuery
{
    /**
     * Build a repository-scoped webhook delivery query using the accepted history filters.
     *
     * @param  array{delivery_status: ?string, delivery_date_from: ?string, delivery_date_to: ?string}  $filters
     * @return HasMany<RepositoryWebhookDelivery, Repository> The filtered delivery relationship.
     */
    public function for(Repository $repository, array $filters, User $actor): HasMany
    {
        return $this->scope($repository->webhookDeliveries(), $actor)
            ->when($filters['delivery_status'], fn ($query, string $status) => $query
                ->where('status', $status))
            ->when($filters['delivery_date_from'], fn ($query, string $date) => $query
                ->whereDate('created_at', '>=', $date))
            ->when($filters['delivery_date_to'], fn ($query, string $date) => $query
                ->whereDate('created_at', '<=', $date));
    }

    /** Filter stored build payloads before listing, aggregation, or CSV export. */
    public function scope(Builder|Relation $query, User $actor): Builder|Relation
    {
        if (! app(ProductAuthentication::class)->usesCoreAuthority('deployer')) {
            return $query;
        }

        $projection = app(DeployerResourceProjection::class);

        return $query->whereIn('repository_webhook_deliveries.repository_id', $projection->repositories(Repository::query(), $actor)->select('repositories.id'))
            ->where(fn (Builder $delivery) => $delivery->whereNull('build_id')
                ->orWhereHas('build', fn (Builder $build) => $projection->builds($build, $actor)
                    ->whereColumn('builds.repository_id', 'repository_webhook_deliveries.repository_id')));
    }

    /**
     * Summarize delivery states for the selected repository and history filters.
     *
     * @param  array{delivery_status: ?string, delivery_date_from: ?string, delivery_date_to: ?string}  $filters
     * @return array{total: int, queued: int, pending: int, skipped: int, unavailable: int, superseded: int, received: int}
     */
    public function metrics(Repository $repository, array $filters, User $actor): array
    {
        $counts = $this->for($repository, $filters, $actor)
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
