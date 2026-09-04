<?php

namespace App\Http\Controllers;

use App\Models\Build;
use App\Models\Provider;
use App\Models\Recipe;
use App\Models\Repository;
use App\Models\Server;
use App\Models\Website;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SearchController extends Controller
{
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

        $websites = $user->websites()
            ->select(['id', 'user_id', 'name', 'url'])
            ->where(function ($builder) use ($query): void {
                $builder
                    ->where('name', 'like', "%{$query}%")
                    ->orWhere('url', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%");
            })
            ->orderBy('name')
            ->limit(6)
            ->get()
            ->map(fn (Website $website): array => [
                'title' => $website->name,
                'subtitle' => $website->url,
                'url' => route('websites.show', $website),
            ]);

        $servers = $user->servers()
            ->select(['id', 'user_id', 'name', 'display_name', 'provisioning_status'])
            ->where(function ($builder) use ($query): void {
                $builder
                    ->where('display_name', 'like', "%{$query}%")
                    ->orWhere('name', 'like', "%{$query}%")
                    ->orWhere('identifier', 'like', "%{$query}%")
                    ->orWhere('public_ip', 'like', "%{$query}%")
                    ->orWhere('private_ip', 'like', "%{$query}%");
            })
            ->orderBy('name')
            ->limit(6)
            ->get()
            ->map(fn (Server $server): array => [
                'title' => $server->label,
                'subtitle' => str($server->provisioning_status)->replace('_', ' ')->title()->toString(),
                'url' => route('servers.show', $server),
            ]);

        $repositories = $user->repositories()
            ->select(['id', 'user_id', 'name', 'url'])
            ->where(function ($builder) use ($query): void {
                $builder
                    ->where('name', 'like', "%{$query}%")
                    ->orWhere('url', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%");
            })
            ->orderBy('name')
            ->limit(6)
            ->get()
            ->map(fn (Repository $repository): array => [
                'title' => $repository->name,
                'subtitle' => $repository->url,
                'url' => route('repositories.show', $repository),
            ]);

        $providers = $user->providers()
            ->select(['id', 'user_id', 'name', 'provider', 'connection_status'])
            ->where(function ($builder) use ($query): void {
                $builder
                    ->where('name', 'like', "%{$query}%")
                    ->orWhere('provider', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%");
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

        $recipes = $user->recipes()
            ->select(['id', 'user_id', 'name', 'description'])
            ->where(function ($builder) use ($query): void {
                $builder
                    ->where('name', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%");
            })
            ->orderBy('name')
            ->limit(6)
            ->get()
            ->map(fn (Recipe $recipe): array => [
                'title' => $recipe->name,
                'subtitle' => $recipe->description,
                'url' => route('recipes.show', $recipe),
            ]);

        $builds = $user->builds()
            ->select(['builds.id', 'builds.repository_id', 'builds.status', 'builds.revision', 'builds.commit_message'])
            ->with('repository:id,name')
            ->where(function ($builder) use ($query): void {
                $builder
                    ->where('builds.revision', 'like', "%{$query}%")
                    ->orWhere('builds.commit_message', 'like', "%{$query}%");
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
