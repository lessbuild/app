<?php

namespace App\Core\Services;

use App\Core\Data\Projects\ProductProjectContextState;
use App\Core\Data\Projects\ProjectEnvironmentContextState;
use App\Core\Data\Projects\ProjectResourceDestinationState;
use App\Core\Data\Projects\ResolvedProductProjectContext;
use App\Core\Enums\ProductKey;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectResource;
use App\Core\Services\Projects\ResolveProjectEnvironmentContext;
use Illuminate\Support\Str;

final class ResolveProductProjectContext
{
    public function __construct(
        private readonly WorkspaceProjectAccess $access,
        private readonly ResolveProjectEnvironmentContext $environmentContexts,
        private readonly ProjectResourceDestinations $resourceDestinations,
        private readonly BuildProjectContextNavigation $contextNavigation,
    ) {}

    public function handle(
        PlatformUser $user,
        string $product,
        mixed $requestedProjectId,
        mixed $requestedEnvironmentId,
        ?string $sourceResourceType,
        string|int|null $sourceResourceId,
    ): ResolvedProductProjectContext {
        $hasProjectContext = $requestedProjectId !== null && $requestedProjectId !== '';
        $hasEnvironmentContext = $requestedEnvironmentId !== null && $requestedEnvironmentId !== '';

        if (! $hasProjectContext && ! $hasEnvironmentContext) {
            return new ResolvedProductProjectContext(ProductProjectContextState::None);
        }

        if (ProductKey::tryFrom($product) === null
            || ! is_string($requestedProjectId)
            || ! Str::isUlid($requestedProjectId)
            || $sourceResourceType === null
            || $sourceResourceId === null) {
            return $this->unavailable();
        }

        if ($hasEnvironmentContext && (! is_string($requestedEnvironmentId) || ! Str::isUlid($requestedEnvironmentId))) {
            return $this->unavailable();
        }

        $project = Project::query()
            ->with(['workspace', 'resources'])
            ->whereKey($requestedProjectId)
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->first();

        if ($project === null || $project->workspace === null
            || ! $this->access->canAccessProductResource($user, $project, $product)) {
            return $this->unavailable();
        }

        $environmentContext = $this->environmentContexts->handle($project, $requestedEnvironmentId);

        if ($environmentContext->isUnavailable()) {
            return $this->unavailable();
        }

        $currentMappings = $project->resources
            ->filter(fn (ProjectResource $resource): bool => $resource->product === $product
                && $resource->resource_type === $sourceResourceType
                && (string) $resource->resource_id === (string) $sourceResourceId
                && $resource->status === 'active')
            ->values();

        if ($currentMappings->count() !== 1) {
            return $this->unavailable();
        }

        $currentMapping = $currentMappings->sole();
        $expectedRootType = match ($product) {
            'deployer' => 'project',
            'monitor' => 'application',
            'analytics' => 'site',
        };
        $isDeployerProjectContext = $product === 'deployer' && $sourceResourceType === 'project';

        if ($environmentContext->state === ProjectEnvironmentContextState::All) {
            if ($sourceResourceType !== $expectedRootType || $currentMapping->environment_id !== null) {
                return $this->unavailable();
            }
        } elseif ($isDeployerProjectContext) {
            if ($currentMapping->environment_id !== null) {
                return $this->unavailable();
            }
        } elseif (! in_array($sourceResourceType, ['environment', 'site'], true)
            || (string) $currentMapping->environment_id !== (string) $environmentContext->environment?->getKey()) {
            return $this->unavailable();
        }

        $destinations = $this->resourceDestinations->forResources($user, $project->resources);
        $currentDestination = $destinations[(string) $currentMapping->getKey()] ?? null;

        if ($currentDestination?->state !== ProjectResourceDestinationState::Available
            || ! filled($currentDestination->url)) {
            return $this->unavailable();
        }

        if ($environmentContext->isSelected() && $isDeployerProjectContext) {
            $environmentMappings = $project->resources
                ->filter(fn (ProjectResource $resource): bool => $resource->product === 'deployer'
                    && $resource->resource_type === 'environment'
                    && (string) $resource->environment_id === (string) $environmentContext->environment?->getKey()
                    && $resource->status === 'active')
                ->values();
            $environmentMapping = $environmentMappings->count() === 1 ? $environmentMappings->sole() : null;
            $environmentDestination = $environmentMapping === null
                ? null
                : ($destinations[(string) $environmentMapping->getKey()] ?? null);
            $projectScope = $this->destinationScope($currentDestination->url);
            $environmentScope = $environmentDestination?->url === null
                ? null
                : $this->destinationScope($environmentDestination->url);

            if ($environmentDestination?->state !== ProjectResourceDestinationState::Available
                || ! filled($environmentDestination->url)
                || $projectScope === null
                || $environmentScope === null
                || $projectScope !== $environmentScope) {
                return $this->unavailable();
            }
        }

        $navigation = $this->contextNavigation->forProject(
            $project->workspace,
            $project,
            $environmentContext,
            $destinations,
        );

        return new ResolvedProductProjectContext(
            ProductProjectContextState::Available,
            project: $project,
            environment: $environmentContext,
            productUrlOverrides: $navigation['urls'],
        );
    }

    private function unavailable(): ResolvedProductProjectContext
    {
        return new ResolvedProductProjectContext(ProductProjectContextState::Unavailable);
    }

    private function destinationScope(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ! filled($parts['host'] ?? null)
            || ! isset($parts['path'])) {
            return null;
        }

        return strtolower((string) $parts['scheme'].'://'.$parts['host']).
            (isset($parts['port']) ? ':'.$parts['port'] : '')
            .$parts['path'];
    }
}
