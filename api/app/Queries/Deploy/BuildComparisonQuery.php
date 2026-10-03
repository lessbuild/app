<?php

declare(strict_types=1);

namespace App\Queries\Deploy;

use App\Data\Deploy\BuildChange;
use App\Data\Deploy\BuildComparison;
use App\Models\Build;

/**
 * Compares two deploys of one repository: their outcomes and timing, a link to the code changes at the Git provider,
 * and the settings that differ between the environment snapshots they were queued with. Variable values, the .env file
 * and resource configuration are secret, so only their names are compared.
 */
final class BuildComparisonQuery
{
    /**
     * Compare a deploy with a baseline from the same repository.
     *
     * @param  Build  $build
     * @param  Build  $baseline
     * @return BuildComparison
     */
    public function handle(Build $build, Build $baseline): BuildComparison
    {
        $build->loadMissing(['repository.provider', 'requester', 'environment']);
        $baseline->loadMissing(['requester', 'environment']);
        $from = $baseline->environment_payload;
        $to = $build->environment_payload;
        $snapshots = is_array($from) && is_array($to) && isset($from['runtime'], $to['runtime']);

        return new BuildComparison(
            build: $build,
            baseline: $baseline,
            durationDelta: $this->duration($build) !== null && $this->duration($baseline) !== null ? $this->duration($build) - $this->duration($baseline) : null,
            compareUrl: $baseline->revision !== $build->revision ? $build->repository->compareUrl($baseline->revision, $build->revision) : null,
            changes: $snapshots ? $this->changes($from, $to) : [],
            snapshotsAvailable: $snapshots,
        );
    }

    /**
     * Work out every setting that differs between two environment snapshots.
     *
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return list<BuildChange>
     */
    private function changes(array $from, array $to): array
    {
        $changes = [];
        if (($from['base_environment'] ?? null) !== ($to['base_environment'] ?? null)) {
            $changes[] = new BuildChange('Environment file', '.env', 'changed');
        }
        if (($from['repository_root'] ?? null) !== ($to['repository_root'] ?? null)) {
            $changes[] = new BuildChange('Runtime', 'repository_root', 'changed', $this->text($from['repository_root'] ?? null), $this->text($to['repository_root'] ?? null));
        }
        $changes = [...$changes, ...$this->compare('Runtime', (array) ($from['runtime'] ?? []), (array) ($to['runtime'] ?? []), secret: false)];
        $changes = [...$changes, ...$this->compare('Variables', (array) ($from['variables'] ?? []), (array) ($to['variables'] ?? []), secret: true)];
        $changes = [...$changes, ...$this->compare('Build variables', (array) ($from['build_variables'] ?? []), (array) ($to['build_variables'] ?? []), secret: true)];
        $changes = [...$changes, ...$this->compare('Workers', $this->byName((array) ($from['processes'] ?? [])), $this->byName((array) ($to['processes'] ?? [])), secret: false)];

        return [...$changes, ...$this->compare('Resources', $this->byName((array) ($from['resources'] ?? [])), $this->byName((array) ($to['resources'] ?? [])), secret: true)];
    }

    /**
     * Compare two keyed maps, reporting additions, removals and changes; secret maps report names only.
     *
     * @param  string  $area
     * @param  array<array-key, mixed>  $from
     * @param  array<array-key, mixed>  $to
     * @param  bool  $secret
     * @return list<BuildChange>
     */
    private function compare(string $area, array $from, array $to, bool $secret): array
    {
        $changes = [];
        $keys = array_unique([...array_keys($from), ...array_keys($to)]);
        sort($keys);
        foreach ($keys as $key) {
            $before = $from[$key] ?? null;
            $after = $to[$key] ?? null;
            $kind = match (true) {
                ! array_key_exists($key, $from) => 'added',
                ! array_key_exists($key, $to) => 'removed',
                $before !== $after => 'changed',
                default => null,
            };
            if ($kind !== null) {
                $changes[] = new BuildChange($area, (string) $key, $kind, $secret ? null : $this->text($before), $secret ? null : $this->text($after));
            }
        }

        return $changes;
    }

    /**
     * Key a list of workers or resources by name.
     *
     * @param  array<array-key, mixed>  $items
     * @return array<string, mixed>
     */
    private function byName(array $items): array
    {
        $named = [];
        foreach ($items as $item) {
            if (is_array($item) && is_scalar($item['name'] ?? null)) {
                $named[(string) $item['name']] = $item;
            }
        }

        return $named;
    }

    /**
     * Show a setting's value as short text, or null when it's missing.
     *
     * @param  mixed  $value
     * @return string|null
     */
    private function text(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'yes' : 'no',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES),
        };
    }

    /**
     * Get how long a deploy took, in seconds, once it has finished.
     *
     * @param  Build  $build
     * @return int|null
     */
    private function duration(Build $build): ?int
    {
        return $build->started_at !== null && $build->finished_at !== null ? (int) $build->started_at->diffInSeconds($build->finished_at, true) : null;
    }
}
