<?php

declare(strict_types=1);

namespace App\Services\Telemetry;

use App\Data\Telemetry\ReleaseIdentity;
use App\Enums\IngestSource;
use App\Models\Environment;
use App\Models\Release;
use Carbon\CarbonImmutable;
use UnexpectedValueException;

final class RecordReleases
{
    /**
     * The caller holds the application lock within its transaction.
     *
     * @param  string  $projectId
     * @param  ReleaseIdentity  $identity
     * @return Release
     */
    public function resolve(string $projectId, ReleaseIdentity $identity): Release
    {
        return Release::query()->firstOrCreate([
            'project_id' => $projectId, 'service_hash' => $identity->serviceHash(), 'version_hash' => $identity->versionHash(),
        ], [
            'version' => $identity->version, 'service' => $identity->service, 'service_namespace' => $identity->namespace,
        ]);
    }

    /**
     * Group once per receipt so repeated spans do not perform per-event release queries.
     * All changes share the worker's event/usage transaction and roll back on failure.
     *
     * @param  Environment  $environment
     * @param  array<int, mixed>  $payload  the stored batch: a list of {identity_id, event}
     * @param  IngestSource  $source
     * @param  CarbonImmutable  $receivedAt
     * @return array<int, int> Payload position to release ID.
     */
    public function record(Environment $environment, array $payload, IngestSource $source, CarbonImmutable $receivedAt): array
    {
        $groups = [];

        foreach ($payload as $position => $item) {
            if (! is_array($item) || ! is_array($item['event'] ?? null)) {
                throw new UnexpectedValueException('The stored ingestion event is unavailable.');
            }

            $identity = ReleaseIdentity::fromEvent($item['event'], $source);

            if ($identity === null) {
                continue;
            }

            $time = isset($item['event']['timestamp']) ? CarbonImmutable::parse($item['event']['timestamp'])->utc() : $receivedAt;
            $key = $identity->serviceHash().':'.$identity->versionHash();

            if (! isset($groups[$key])) {
                $groups[$key] = ['identity' => $identity, 'first' => $time, 'last' => $time, 'positions' => []];
            }

            $groups[$key]['first'] = $time->min($groups[$key]['first']);
            $groups[$key]['last'] = $time->max($groups[$key]['last']);
            $groups[$key]['positions'][] = $position;
        }

        $releaseIds = [];

        foreach ($groups as $group) {
            $release = $this->resolve($environment->project_id, $group['identity']);
            $release->fill([
                'first_seen_at' => $release->first_seen_at === null ? $group['first'] : $group['first']->min($release->first_seen_at),
                'last_seen_at' => $release->last_seen_at === null ? $group['last'] : $group['last']->max($release->last_seen_at),
            ])->save();

            foreach ($group['positions'] as $position) {
                $releaseIds[$position] = $release->id;
            }
        }

        return $releaseIds;
    }
}
