<?php

namespace App\Services;

use App\Data\RepositoryChangeImpact;
use App\Models\Repository;
use App\Support\RepositoryPath;

class RepositoryChangeImpactEvaluator
{
    /**
     * Evaluate whether provider-reported changed paths affect one automatic deployment target.
     *
     * An empty filter configuration preserves the existing deploy-every-matching-push
     * behavior. When filters are configured, unavailable or malformed changed-path
     * data remains deployable rather than being silently skipped.
     *
     * @param  Repository  $repository  Deployment target carrying its optional path filters.
     * @param  list<string>|null  $changedPaths  Normalized provider paths, or null when the provider did not provide them.
     * @return RepositoryChangeImpact The conservative deployment decision.
     */
    public function evaluate(Repository $repository, ?array $changedPaths): RepositoryChangeImpact
    {
        $include = $this->patterns($repository->auto_deploy_include_paths);
        $exclude = $this->patterns($repository->auto_deploy_exclude_paths);
        if ($include === null || $exclude === null) {
            return new RepositoryChangeImpact(
                RepositoryChangeImpact::UNKNOWN,
                $changedPaths,
                reason: 'invalid_path_filter',
            );
        }
        if ($include === [] && $exclude === []) {
            return new RepositoryChangeImpact(
                RepositoryChangeImpact::AFFECTED,
                $changedPaths,
                reason: 'no_path_filters',
            );
        }
        if ($changedPaths === null) {
            return new RepositoryChangeImpact(
                RepositoryChangeImpact::UNKNOWN,
                reason: 'changed_paths_unavailable',
            );
        }

        $paths = $this->paths($changedPaths);
        if ($paths === null) {
            return new RepositoryChangeImpact(
                RepositoryChangeImpact::UNKNOWN,
                reason: 'invalid_changed_paths',
            );
        }

        $matchedPaths = array_values(array_filter(
            $paths,
            fn (string $path): bool => $this->matchesScope($path, $include, $exclude),
        ));
        if ($matchedPaths !== []) {
            return new RepositoryChangeImpact(
                RepositoryChangeImpact::AFFECTED,
                $paths,
                $matchedPaths,
                'configured_path_changed',
            );
        }

        return new RepositoryChangeImpact(
            RepositoryChangeImpact::UNAFFECTED,
            $paths,
            reason: 'no_configured_path_changed',
        );
    }

    /**
     * Merge all path sets retained while a website deployment is active.
     *
     * A single unavailable set makes the aggregate unknown so coalescing cannot
     * skip an earlier relevant change. An empty set is retained as known data.
     *
     * @param  list<list<string>|null>  $pathSets  Delivery path sets in receipt order.
     * @return list<string>|null Unique normalized paths, or null when any set is unavailable or unsafe.
     */
    public function mergeChangedPaths(array $pathSets): ?array
    {
        $merged = [];
        foreach ($pathSets as $paths) {
            if ($paths === null || count($paths) > RepositoryPath::MAX_CHANGED_PATHS) {
                return null;
            }

            foreach ($paths as $path) {
                $normalized = RepositoryPath::normalize($path);
                if ($normalized === null) {
                    return null;
                }
                $merged[$normalized] = true;
                if (count($merged) > RepositoryPath::MAX_CHANGED_PATHS) {
                    return null;
                }
            }
        }

        return array_keys($merged);
    }

    /**
     * Normalize persisted filter values and treat corrupted configuration as unknown.
     *
     * @param  mixed  $patterns  Cast repository filter value.
     * @return list<string>|null Normalized patterns, or null for invalid persisted data.
     */
    private function patterns(mixed $patterns): ?array
    {
        if ($patterns === null) {
            return [];
        }
        if (! is_array($patterns)) {
            return null;
        }

        $normalized = [];
        foreach ($patterns as $pattern) {
            $pattern = RepositoryPath::normalizePattern($pattern);
            if ($pattern === null) {
                return null;
            }
            $normalized[$pattern] = true;
        }

        return array_keys($normalized);
    }

    /**
     * Normalize provider paths before matching persisted filters.
     *
     * @param  list<string>  $changedPaths  Provider-reported paths.
     * @return list<string>|null Normalized unique paths, or null for unsafe data.
     */
    private function paths(array $changedPaths): ?array
    {
        if (count($changedPaths) > RepositoryPath::MAX_CHANGED_PATHS) {
            return null;
        }

        $normalized = [];
        foreach ($changedPaths as $path) {
            $path = RepositoryPath::normalize($path);
            if ($path === null) {
                return null;
            }
            $normalized[$path] = true;
        }

        return array_keys($normalized);
    }

    /**
     * Apply include rules followed by exclude rules, with exclusions winning.
     *
     * @param  string  $path  Normalized changed path.
     * @param  list<string>  $include  Inclusive path patterns; empty means all paths.
     * @param  list<string>  $exclude  Exclusive path patterns.
     * @return bool Whether the path affects the deployment target.
     */
    private function matchesScope(string $path, array $include, array $exclude): bool
    {
        foreach ($exclude as $pattern) {
            if (RepositoryPath::matches($pattern, $path)) {
                return false;
            }
        }

        if ($include === []) {
            return true;
        }

        foreach ($include as $pattern) {
            if (RepositoryPath::matches($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
