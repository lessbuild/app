<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Data\Telemetry\IngestSource;
use App\Modules\Monitor\Data\Telemetry\ReleaseIdentity;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Release;
use Carbon\CarbonImmutable;
use UnexpectedValueException;

final class RecordReleases
{
    /** The caller holds the application lock within its transaction. */
    public function resolve(int $applicationId, ReleaseIdentity $identity): Release
    {
        return Release::query()->firstOrCreate([
            'application_id' => $applicationId, 'service_hash' => $identity->serviceHash(), 'version_hash' => $identity->versionHash(),
        ], [
            'version' => $identity->version, 'service' => $identity->service, 'service_namespace' => $identity->namespace,
        ]);
    }

    /**
     * Group once per receipt so repeated spans do not perform per-event release queries.
     * All changes share the worker's event/usage transaction and roll back on failure.
     *
     * @param  list<array{identity_id: int, event: array<string, mixed>}>  $payload
     * @return array<int, int> Payload position to release ID.
     */
    public function record(Environment $environment, array $payload, IngestSource $source, CarbonImmutable $receivedAt): array
    {
        $groups = [];

        foreach ($payload as $position => $item) {
            if (! is_array($item['event'] ?? null)) {
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
            $release = $this->resolve($environment->application_id, $group['identity']);
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
