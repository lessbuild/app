<?php

namespace App\Http\Controllers;

use App\Models\Build;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Website;
use App\Support\SqlLike;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SearchController extends Controller
{
    /**
     * Render workspace-scoped result groups for a trimmed query of at most 100 characters.
     */
    public function __invoke(Request $request): View
    {
        $query = str($request->string('q')->toString())->trim()->limit(100, '')->toString();

        return view('search.index', [
            'query' => $query,
            'groups' => $query === '' ? [] : $this->groups($request, $query),
        ]);
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
