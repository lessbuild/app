<?php

namespace App\Modules\Deployer\Http\Controllers;

use App\Core\Services\Search\ProductWorkspaceSearch;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\Provider;
use App\Modules\Deployer\Models\Recipe;
use App\Modules\Deployer\Models\Repository;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Models\Website;
use App\Modules\Deployer\Support\SqlLike;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

class SearchController extends Controller
{
    /**
     * Render workspace-scoped result groups for a trimmed query of at most 100 characters.
     */
    public function __invoke(Request $request, ProductWorkspaceSearch $crossAppSearch): Response
    {
        $query = str($request->string('q')->toString())->trim()->limit(100, '')->toString();

        $groups = $query === '' ? [] : $this->groups($request, $query);
        $unavailable = [];
        $user = $request->user();

        if ($query !== '' && $user instanceof User && $user->current_organization_id !== null) {
            $platformResults = $crossAppSearch->fromSourceWorkspace(
                principal: $user,
                product: 'deployer',
                sourceEntity: 'organization',
                sourceWorkspaceId: (string) $user->current_organization_id,
                query: $query,
            );

            if ($platformResults !== null) {
                $unavailable = array_values(array_filter(
                    $platformResults['unavailable'],
                    static fn (array $product): bool => $product['product'] !== 'deployer',
                ));

                foreach ($platformResults['groups'] as $index => $group) {
                    if ($group['product'] === 'deployer') {
                        continue;
                    }

                    $results = collect($group['results'])->map(static fn (array $result): array => [
                        'title' => $result['title'],
                        'subtitle' => $result['subtitle'],
                        'url' => $result['url'],
                    ]);

                    if ($results->isEmpty()) {
                        continue;
                    }

                    $key = 'connected-'.$group['product'].'-'.$index;
                    $groups[$key] = [
                        'label' => $group['label'],
                        'results' => $results,
                        'has_more' => false,
                        'more_url' => route('search.index', ['q' => $query]),
                    ];
                }
            }
        }

        if ($request->string('fragment')->toString() === 'workspace') {
            return response()
                ->view('search._workspace-results', compact('query', 'groups', 'unavailable'))
                ->header('Cache-Control', 'private, no-store');
        }

        return response()
            ->view('search.index', [
                'query' => $query,
                'groups' => $groups,
                'unavailable' => $unavailable,
            ])
            ->header('Cache-Control', 'private, no-store');
    }

    /** @return array<string, array{label: string, results: Collection<int, array{title: string, subtitle: ?string, url: string}>, has_more: bool, more_url: string}> */
    private function groups(Request $request, string $query): array
    {
        $user = $request->user();
        $pattern = SqlLike::contains($query);

        $projects = $user->currentOrganization->projects()
            ->where(function ($builder) use ($pattern): void {
                $builder
                    ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("description LIKE ? ESCAPE '!'", [$pattern]);
            })
            ->withCount('environments')
            ->orderBy('name')
            ->limit(6)
            ->get()
            ->map(fn (Project $project): array => [
                'title' => $project->name,
                'subtitle' => trans_choice(':count environment|:count environments', $project->environments_count, ['count' => $project->environments_count]),
                'url' => route('projects.show', $project),
            ]);

        $websites = $user->workspaceWebsites()
            ->select(['id', 'user_id', 'name', 'url'])
            ->where(function ($builder) use ($pattern): void {
                $builder
                    ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("url LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("description LIKE ? ESCAPE '!'", [$pattern]);
            })
            ->orderBy('name')
            ->limit(6)
            ->get()
            ->map(fn (Website $website): array => [
                'title' => $website->name,
                'subtitle' => $website->url,
                'url' => route('websites.show', $website),
            ]);

        $servers = $user->workspaceServers()
            ->select(['id', 'user_id', 'name', 'display_name', 'provisioning_status'])
            ->where(function ($builder) use ($pattern): void {
                $builder
                    ->whereRaw("display_name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("identifier LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("public_ip LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("private_ip LIKE ? ESCAPE '!'", [$pattern]);
            })
            ->orderBy('name')
            ->limit(6)
            ->get()
            ->map(fn (Server $server): array => [
                'title' => $server->label,
                'subtitle' => str($server->provisioning_status)->replace('_', ' ')->title()->toString(),
                'url' => route('servers.show', $server),
            ]);

        $repositories = $user->workspaceRepositories()
            ->select(['id', 'user_id', 'name', 'url'])
            ->where(function ($builder) use ($pattern): void {
                $builder
                    ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("url LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("description LIKE ? ESCAPE '!'", [$pattern]);
            })
            ->orderBy('name')
            ->limit(6)
            ->get()
            ->map(fn (Repository $repository): array => [
                'title' => $repository->name,
                'subtitle' => $repository->url,
                'url' => route('repositories.show', $repository),
            ]);

        $providers = $user->workspaceProviders()
            ->select(['id', 'user_id', 'name', 'provider', 'connection_status'])
            ->where(function ($builder) use ($pattern): void {
                $builder
                    ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("provider LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("description LIKE ? ESCAPE '!'", [$pattern]);
            })
            ->orderBy('name')
            ->limit(6)
            ->get()
            ->map(fn (Provider $provider): array => [
                'title' => $provider->name,
                'subtitle' => collect([
                    str($provider->provider)->title()->toString(),
                    str($provider->connectionHealth())->replace('_', ' ')->title()->toString(),
                ])->implode(' · '),
                'url' => route('providers.show', $provider),
            ]);

        $recipes = $user->workspaceRecipes()
            ->select(['id', 'user_id', 'name', 'description'])
            ->where(function ($builder) use ($pattern): void {
                $builder
                    ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("description LIKE ? ESCAPE '!'", [$pattern]);
            })
            ->orderBy('name')
            ->limit(6)
            ->get()
            ->map(fn (Recipe $recipe): array => [
                'title' => $recipe->name,
                'subtitle' => $recipe->description,
                'url' => route('recipes.show', $recipe),
            ]);

        $builds = Build::query()
            ->whereHas('repository', fn ($repository) => $repository->where('organization_id', $user->current_organization_id))
            ->select(['builds.id', 'builds.repository_id', 'builds.status', 'builds.revision', 'builds.commit_message'])
            ->with('repository:id,name')
            ->where(function ($builder) use ($pattern): void {
                $builder
                    ->whereRaw("builds.revision LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("builds.commit_message LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('repository', fn ($repository) => $repository
                        ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
            })
            ->latest('builds.id')
            ->limit(6)
            ->get()
            ->map(fn (Build $build): array => [
                'title' => __('Build #:id', ['id' => $build->id]),
                'subtitle' => collect([
                    $build->repository->name,
                    str($build->status)->replace('_', ' ')->title()->toString(),
                    $build->shortRevision(),
                ])->filter()->implode(' · '),
                'url' => route('builds.show', $build),
            ]);

        return [
            'projects' => $this->group(__('Applications'), $projects, route('projects.index')),
            'websites' => $this->group(__('Websites'), $websites, route('websites.index', ['search' => $query])),
            'servers' => $this->group(__('Servers'), $servers, route('servers.index', ['search' => $query])),
            'repositories' => $this->group(__('Repositories'), $repositories, route('repositories.index', ['search' => $query])),
            'providers' => $this->group(__('Providers'), $providers, route('providers.index', ['search' => $query])),
            'recipes' => $this->group(__('Recipes'), $recipes, route('recipes.index', ['search' => $query])),
            'builds' => $this->group(__('Builds'), $builds, route('builds.index', ['search' => $query])),
        ];
    }

    /**
     * @param  Collection<int, array{title: string, subtitle: ?string, url: string}>  $results
     * @return array{label: string, results: Collection<int, array{title: string, subtitle: ?string, url: string}>, has_more: bool, more_url: string}
     */
    private function group(string $label, Collection $results, string $moreUrl): array
    {
        return [
            'label' => $label,
            'results' => $results->take(5)->values(),
            'has_more' => $results->count() > 5,
            'more_url' => $moreUrl,
        ];
    }
}
