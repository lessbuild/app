<?php

namespace App\Core\Services;

use App\Core\Data\Projects\ProjectEnvironmentContext;
use App\Core\Data\Projects\ProjectResourceDestination;
use App\Core\Data\Projects\ProjectResourceDestinationState;
use App\Core\Models\Project;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Uri;

final class BuildProjectContextNavigation
{
    private const PRODUCTS = ['deployer', 'monitor', 'analytics'];

    /**
     * @param  array<string, ProjectResourceDestination>  $resourceDestinations
     * @return array{links: array<string, ?string>, urls: array<string, string>, status: array<string, ?string>}
     */
    public function forProject(
        Workspace $workspace,
        Project $project,
        ProjectEnvironmentContext $environmentContext,
        array $resourceDestinations,
    ): array {
        $query = ['context_project' => $project->getKey()];

        if ($environmentContext->wasRequested()) {
            $query['context_environment'] = $environmentContext->requestedId;
        }

        $fallbackUrl = (string) Uri::of(route('core.projects.show', [$workspace, $project]))->withQuery($query);

        if ($environmentContext->wasRequested()) {
            $fallbackUrl .= '#environments';
        }

        $links = [];
        $urls = [];
        $status = [];

        foreach (self::PRODUCTS as $product) {
            if ($environmentContext->isUnavailable()) {
                $links[$product] = null;
                $urls[$product] = $fallbackUrl;
                $status[$product] = __('Selected environment unavailable');

                continue;
            }

            $resourceType = match ([$product, $environmentContext->isSelected()]) {
                ['deployer', true], ['monitor', true] => 'environment',
                ['deployer', false] => 'project',
                ['monitor', false] => 'application',
                ['analytics', true], ['analytics', false] => 'site',
            };
            $mappings = $project->resources
                ->filter(fn (ProjectResource $resource): bool => $resource->product === $product
                    && $resource->resource_type === $resourceType
                    && ($environmentContext->isSelected()
                        ? (string) $resource->environment_id === (string) $environmentContext->environment?->getKey()
                        : $resource->environment_id === null))
                ->values();
            $activeMappings = $mappings->where('status', 'active')->values();
            $availableMappings = $activeMappings->filter(function (ProjectResource $resource) use ($resourceDestinations): bool {
                $destination = $resourceDestinations[(string) $resource->getKey()] ?? null;

                return $destination?->state === ProjectResourceDestinationState::Available
                    && filled($destination->url);
            })->values();

            if ($activeMappings->count() === 1 && $availableMappings->count() === 1) {
                $destination = $resourceDestinations[(string) $availableMappings->sole()->getKey()];
                $links[$product] = $destination->url;
                $urls[$product] = (string) Uri::of($destination->url)->withQuery($query);
                $status[$product] = null;

                continue;
            }

            $links[$product] = null;
            $urls[$product] = $environmentContext->isSelected()
                ? $fallbackUrl
                : ($this->productHomeUrl($product) ?? $fallbackUrl);
            $status[$product] = $this->unavailableReason($mappings, $activeMappings, $availableMappings, $resourceDestinations, $environmentContext);
        }

        return compact('links', 'urls', 'status');
    }

    private function productHomeUrl(string $product): ?string
    {
        $routeName = match ($product) {
            'deployer' => 'dashboard',
            'monitor' => 'monitor.dashboard',
            'analytics' => 'analytics.dashboard',
        };

        if (Route::has($routeName)) {
            return route($routeName);
        }

        $url = config("platform.products.{$product}.url");

        return is_string($url) && filled($url) ? $url : null;
    }

    private function unavailableReason(
        Collection $mappings,
        Collection $activeMappings,
        Collection $availableMappings,
        array $resourceDestinations,
        ProjectEnvironmentContext $environmentContext,
    ): string {
        if ($mappings->isEmpty()) {
            return $environmentContext->isSelected()
                ? __('Not mapped to this environment')
                : __('Not mapped to this project');
        }

        if ($activeMappings->count() > 1 || $availableMappings->count() > 1) {
            return __('Multiple mappings need review');
        }

        $mapping = $mappings->first();
        $destination = $mapping === null ? null : ($resourceDestinations[(string) $mapping->getKey()] ?? null);

        return $destination?->state->label() ?? __('Mapping unavailable');
    }
}
