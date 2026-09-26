<?php

namespace App\Core\Services;

use App\Core\Data\Projects\ProjectEnvironmentContext;
use App\Core\Data\Projects\ProjectInfrastructureEdge;
use App\Core\Data\Projects\ProjectInfrastructureNode;
use App\Core\Data\Projects\ProjectInfrastructureSnapshot;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectResource;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteDatabaseDoesNotExistException;
use Illuminate\Support\Str;

/** Live infrastructure is a product projection, never an additional canonical resource store. */
final class ProjectInfrastructure
{
    public function __construct(
        private readonly ProjectInfrastructureProviderRegistry $providers,
        private readonly WorkspaceProjectAccess $access,
    ) {}

    /** @param list<string> $products
     * @return array<string, ProjectInfrastructureSnapshot>
     */
    public function forProject(PlatformUser $user, Project $project, array $products, ?ProjectEnvironmentContext $context = null): array
    {
        $project = Project::query()->find($project->getKey());
        if ($project === null || ! $this->access->canViewProject($user, $project) || $context?->isUnavailable()) {
            return [];
        }
        if ($context?->wasRequested() && (! $context->isSelected() || ! ProjectEnvironment::query()
            ->whereKey($context->environment?->getKey())->where('project_id', $project->getKey())
            ->where('status', 'active')->exists())) {
            return [];
        }
        $snapshots = [];
        foreach (array_unique($products) as $product) {
            $provider = $this->providers->get($product);
            if ($provider === null || ! $this->access->canAccessProductResource($user, $project, $product)) {
                continue;
            }
            $resources = ProjectResource::query()->where('project_id', $project->getKey())
                ->where('product', $product)->where('status', 'active')->with('environment')->get();
            try {
                $snapshot = $provider->forProject($user, $project, $resources, $context);
                $snapshots[$product] = $this->bounded($product, $snapshot);
            } catch (LostConnectionException|QueryException|SQLiteDatabaseDoesNotExistException) {
                $snapshots[$product] = new ProjectInfrastructureSnapshot(available: false);
            }
        }

        return $snapshots;
    }

    private function bounded(string $product, ProjectInfrastructureSnapshot $snapshot): ProjectInfrastructureSnapshot
    {
        if (! $snapshot->available) {
            return new ProjectInfrastructureSnapshot(available: false);
        }
        $nodes = collect($snapshot->nodes)->filter(fn ($node): bool => $node instanceof ProjectInfrastructureNode && $node->product === $product)
            ->unique('key')->take(100)->map(fn (ProjectInfrastructureNode $node): ProjectInfrastructureNode => new ProjectInfrastructureNode(
                $node->key, $product, $this->text($node->kind, 50), $this->text($node->label, 180),
                $this->url($product, $node->url), $node->environmentId,
                $node->environmentName === null ? null : $this->text($node->environmentName, 120),
                $node->status === null ? null : $this->text($node->status, 60),
            ))->values();
        $keys = $nodes->keyBy('key');
        $edges = collect($snapshot->edges)->filter(fn ($edge): bool => $edge instanceof ProjectInfrastructureEdge
            && $keys->has($edge->sourceKey) && $keys->has($edge->targetKey))
            ->unique(fn (ProjectInfrastructureEdge $edge): string => $edge->sourceKey.'|'.$edge->targetKey.'|'.$edge->label)
            ->take(300)->map(fn (ProjectInfrastructureEdge $edge): ProjectInfrastructureEdge => new ProjectInfrastructureEdge(
                $edge->sourceKey, $edge->targetKey, $this->text($edge->label, 80),
            ))->values();

        return new ProjectInfrastructureSnapshot($nodes->all(), $edges->all(), truncated: $snapshot->truncated || count($snapshot->nodes) > 100 || count($snapshot->edges) > 300);
    }

    private function text(string $value, int $limit): string
    {
        return Str::limit(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', strip_tags($value))), $limit);
    }

    private function url(string $product, ?string $url): ?string
    {
        if ($url === null || str_contains($url, '\\') || preg_match('/[\x00-\x20\x7F]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])
            || ! str_starts_with($parts['path'] ?? '', '/') || str_starts_with($parts['path'], '//')) {
            return null;
        }
        $base = config('platform.products.'.$product.'.url');
        if (! is_string($base) || ! str_starts_with($base, 'https://')) {
            $host = config('platform.products.'.$product.'.host');
            if (! is_string($host) || $host === '') {
                return null;
            }
            $base = 'https://'.$host;
        }

        return rtrim($base, '/').$parts['path'].(isset($parts['query']) ? '?'.$parts['query'] : '')
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }
}
