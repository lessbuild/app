<?php

namespace App\Core\Services\Search;

use App\Core\Data\Search\WorkspaceSearchResult;
use App\Core\Enums\ProductKey;
use App\Core\Exceptions\Search\WorkspaceSearchProviderUnavailable;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\Workspace;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;

final class WorkspaceSearch
{
    private const MAX_RESULTS_PER_GROUP = 5;

    public function __construct(
        private readonly WorkspaceSearchProviderRegistry $providers,
        private readonly WorkspaceProjectAccess $access,
    ) {}

    /**
     * @return array{
     *   query:string,
     *   groups:list<array{product:string,label:string,results:list<array{type:string,title:string,subtitle:?string,url:string}>,count:int}>,
     *   unavailable:list<array{product:string,label:string}>
     * }
     */
    public function forWorkspace(PlatformUser $user, Workspace $workspace, string $query): array
    {
        $query = mb_substr(trim($query), 0, 100);
        $membership = $this->access->activeMembership($user, $workspace);

        if ($membership === null || mb_strlen($query) < 2) {
            return ['query' => $query, 'groups' => [], 'unavailable' => []];
        }

        $groups = [];
        $unavailable = [];
        $projectResults = $this->projects($user, $workspace, $query);

        if ($projectResults !== []) {
            $groups[] = $this->group('core', __('Projects'), $projectResults);
        }

        foreach ([ProductKey::Deployer, ProductKey::Monitor, ProductKey::Analytics] as $product) {
            if (! $this->access->hasProductAccess($membership, $product)) {
                continue;
            }

            $key = $product->value;
            $label = (string) config("platform.products.{$key}.label", ucfirst($key));
            $provider = $this->providers->get($key);

            if ($provider === null) {
                $unavailable[] = ['product' => $key, 'label' => $label];

                continue;
            }

            try {
                $resultsByType = collect($provider->search($user, $workspace, $query))
                    ->groupBy(fn (WorkspaceSearchResult $result): string => $result->type);
            } catch (LostConnectionException|QueryException|WorkspaceSearchProviderUnavailable) {
                $unavailable[] = ['product' => $key, 'label' => $label];

                continue;
            }

            foreach ($resultsByType as $type => $results) {
                $results = $results->take(self::MAX_RESULTS_PER_GROUP)->values()->all();

                if ($results !== []) {
                    $groups[] = $this->group($key, $label.' · '.$type, $results);
                }
            }
        }

        return [
            'query' => $query,
            'groups' => $groups,
            'unavailable' => $unavailable,
        ];
    }

    /** @return list<WorkspaceSearchResult> */
    private function projects(PlatformUser $user, Workspace $workspace, string $query): array
    {
        if (! Route::has('core.projects.show')) {
            return [];
        }

        $pattern = WorkspaceSearchPattern::contains($query);

        return Project::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->whereHas('memberships', fn ($memberships) => $memberships
                ->where('user_id', $user->getKey())
                ->where('status', 'active')
                ->whereNull('revoked_at'))
            ->where(fn ($projects) => $projects
                ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("slug LIKE ? ESCAPE '!'", [$pattern]))
            ->orderBy('name')
            ->limit(self::MAX_RESULTS_PER_GROUP)
            ->get(['id', 'workspace_id', 'name'])
            ->map(fn (Project $project): WorkspaceSearchResult => new WorkspaceSearchResult(
                type: __('Project'),
                title: $project->name,
                subtitle: null,
                url: route('core.projects.show', [$workspace, $project]),
            ))
            ->all();
    }

    /** @param list<WorkspaceSearchResult> $results
     * @return array{product:string,label:string,results:list<array{type:string,title:string,subtitle:?string,url:string}>,count:int}
     */
    private function group(string $product, string $label, array $results): array
    {
        $visibleResults = array_map(
            static fn (WorkspaceSearchResult $result): array => $result->toArray(),
            $results,
        );

        return [
            'product' => $product,
            'label' => $label,
            'results' => $visibleResults,
            'count' => count($visibleResults),
        ];
    }
}
