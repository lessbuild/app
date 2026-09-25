<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Provider;
use App\Modules\Deployer\Models\Recipe;
use App\Modules\Deployer\Models\RecipeReport;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Notifications\NotificationInbox;
use App\Modules\Deployer\Services\Core\DeployerProjectAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

class NotificationDestinationResolver
{
    /**
     * Resolve the bounded set of notification destinations against the current actor's visibility.
     *
     * The inbox page is paginated, so this performs at most one existence query
     * per represented category instead of loading one related model per card.
     * A URL is retained for recognized payloads even when the resource is no
     * longer visible; callers can then present a neutral inventory fallback.
     *
     * @param  iterable<DatabaseNotification>  $notifications  The current inbox page.
     * @return array<string, array{url: string, fallback: string, available: bool}> Destination state keyed by notification ID.
     */
    public function for(User $user, iterable $notifications): array
    {
        /** @var Collection<int, DatabaseNotification> $notifications */
        $notifications = collect($notifications)->values();
        $states = [];
        $identifiers = [];

        foreach ($notifications as $notification) {
            $data = $notification->data;
            $url = NotificationInbox::destination($data);
            $category = is_string($data['category'] ?? null) ? $data['category'] : null;
            $resourceId = $this->positiveInteger($data['resource_id'] ?? null);

            if ($url === null || $category === null || $resourceId === null) {
                continue;
            }

            $key = (string) $notification->getKey();
            $states[$key] = [
                'url' => $url,
                'fallback' => $this->fallback($category),
                'available' => false,
            ];
            $identifiers[$category][] = $resourceId;
            if ($category === 'gallery') {
                $reportId = $this->positiveInteger($data['report_id'] ?? null);
                if ($reportId !== null) {
                    $identifiers['gallery_report'][] = $reportId;
                }
            }
        }

        if ($states === []) {
            return [];
        }

        $available = $this->availableIds($user, $identifiers);

        foreach ($notifications as $notification) {
            $data = $notification->data;
            $category = is_string($data['category'] ?? null) ? $data['category'] : null;
            $resourceId = $this->positiveInteger($data['resource_id'] ?? null);
            $key = (string) $notification->getKey();

            if ($category === null || $resourceId === null || ! isset($states[$key])) {
                continue;
            }

            $reportId = $this->positiveInteger($data['report_id'] ?? null);
            $states[$key]['available'] = $category === 'gallery' && $reportId !== null
                ? in_array($reportId, $available['gallery_reports'] ?? [], true)
                : in_array($resourceId, $available[$category] ?? [], true);
        }

        return $states;
    }

    /**
     * Resolve one notification using the same batch-aware code path used by the inbox page.
     *
     * @return array{url: string, fallback: string, available: bool}|null
     */
    public function one(User $user, DatabaseNotification $notification): ?array
    {
        return $this->for($user, [$notification])[(string) $notification->getKey()] ?? null;
    }

    /**
     * Return IDs visible to the current actor, grouped by notification category.
     *
     * @param  array<string, list<int>>  $identifiers
     * @return array<string, list<int>>
     */
    private function availableIds(User $user, array $identifiers): array
    {
        $available = [];
        $organizationId = $user->current_organization_id;
        $canViewWorkspace = $user->currentOrganization?->permits($user, 'view') ?? false;

        foreach ([
            'website' => Website::class,
            'server' => Server::class,
            'provider' => Provider::class,
            'gallery' => Recipe::class,
        ] as $category => $model) {
            if ($category === 'gallery') {
                $available[$category] = $this->publishedRecipeIds($identifiers[$category] ?? []);

                continue;
            }

            $available[$category] = $this->workspaceResourceIds(
                $model,
                $user,
                $identifiers[$category] ?? [],
                $organizationId,
                $canViewWorkspace,
            );
        }

        $available['deployment'] = $this->buildIds(
            $user,
            $identifiers['deployment'] ?? [],
            $organizationId,
            $canViewWorkspace,
        );
        $available['recipe'] = $this->reportIdsForContributor($user, $identifiers['recipe'] ?? []);
        $available['gallery_reports'] = $this->reportIdsForReporter($user, $identifiers['gallery_report'] ?? []);

        $accountId = (int) $user->getKey();
        $available['account'] = in_array($accountId, $identifiers['account'] ?? [], true) ? [$accountId] : [];

        return $available;
    }

    /**
     * Resolve direct workspace resources without loading their sensitive models.
     *
     * @param  class-string  $model
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function workspaceResourceIds(
        string $model,
        User $user,
        array $ids,
        ?int $organizationId,
        bool $canViewWorkspace,
    ): array {
        if ($ids === []) {
            return [];
        }

        $resource = match ($model) {
            Website::class => 'websites', Server::class => 'servers', Provider::class => 'providers',
        };

        return app(DeployerProjectAccess::class)->{$resource}($model::query(), $user)
            ->whereKey(array_values(array_unique($ids)))
            ->where(function (Builder $query) use ($user, $organizationId, $canViewWorkspace): void {
                if ($canViewWorkspace && $organizationId !== null) {
                    $query->where('organization_id', $organizationId);
                }

                $query->orWhere(function (Builder $query) use ($user): void {
                    $query->whereNull('organization_id')->where('user_id', $user->getKey());
                });
            })
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Resolve builds through their repository's existing workspace ownership boundary.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function buildIds(User $user, array $ids, ?int $organizationId, bool $canViewWorkspace): array
    {
        if ($ids === []) {
            return [];
        }

        return app(DeployerProjectAccess::class)->builds(Build::query(), $user)
            ->whereKey(array_values(array_unique($ids)))
            ->whereHas('repository', function (Builder $query) use ($user, $organizationId, $canViewWorkspace): void {
                $query->where(function (Builder $query) use ($user, $organizationId, $canViewWorkspace): void {
                    if ($canViewWorkspace && $organizationId !== null) {
                        $query->where('organization_id', $organizationId);
                    }

                    $query->orWhere(function (Builder $query) use ($user): void {
                        $query->whereNull('organization_id')->where('user_id', $user->getKey());
                    });
                });
            })
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * A report notification is visible to the contributor whose recipe owns the report.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function reportIdsForContributor(User $user, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return RecipeReport::query()
            ->whereKey(array_values(array_unique($ids)))
            ->whereHas('recipe', fn (Builder $query) => $query->where('user_id', $user->getKey()))
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Resolve private gallery report status destinations for the report author.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function reportIdsForReporter(User $user, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return RecipeReport::query()
            ->whereKey(array_values(array_unique($ids)))
            ->where('user_id', $user->getKey())
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Resolve public gallery destinations without exposing unpublished recipes.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function publishedRecipeIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Recipe::query()
            ->published()
            ->whereKey(array_values(array_unique($ids)))
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function positiveInteger(mixed $value): ?int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $validated ?: null;
    }

    private function fallback(string $category): string
    {
        return match ($category) {
            'deployment' => route('builds.index'),
            'website' => route('websites.index'),
            'server' => route('servers.index'),
            'provider' => route('providers.index'),
            'recipe' => route('gallery.reports.index'),
            'gallery' => route('gallery.index'),
            default => route('account.index'),
        };
    }
}
