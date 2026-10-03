<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Data\Telemetry\ReleaseIdentity;
use App\Models\Build;
use App\Models\Deployment;
use App\Services\Telemetry\RecordReleases;
use Carbon\CarbonImmutable;

/**
 * A live build becomes a Monitoring release and deployment marker for its environment (source `deploy`), so issues and
 * incidents link to the deploy that shipped them without the deployments API.
 */
final class DeploymentMarkers
{
    /**
     * Create a new DeploymentMarkers instance.
     *
     * Records live builds on Monitoring's timeline.
     *
     * @param  RecordReleases  $releases  Finds or creates the release.
     */
    public function __construct(private readonly RecordReleases $releases) {}

    /**
     * Record the build as a deployment of its environment, once (keyed by build ID), with its revision as the version
     * and its repository as the service. Null for builds without an environment.
     *
     * @param  Build  $build
     * @return Deployment|null
     */
    public function record(Build $build): ?Deployment
    {
        $environment = $build->environment;
        $identity = ReleaseIdentity::from($build->shortRevision() ?? "build-{$build->id}", $build->repository->name, null);
        if ($environment === null || $identity === null) {
            return null;
        }
        $key = self::keyFor($build->id);
        $existing = $environment->deployments()->where('deployment_key', $key)->first();
        if ($existing !== null) {
            return $existing;
        }
        $release = $this->releases->resolve($environment->project_id, $identity);

        return $environment->deployments()->create([
            'release_id' => $release->id, 'deployment_key' => $key, 'payload_hash' => hash('sha256', "deploy-build:{$build->id}"),
            'actor_id' => $build->requested_by, 'source' => 'deploy', 'commit_sha' => $build->revision,
            'note' => $build->commit_message === null ? null : mb_substr($build->commit_message, 0, 500),
            'deployed_at' => $build->activated_at ?? CarbonImmutable::now('UTC'),
        ]);
    }

    /**
     * Get the deployment key a build's marker is stored under, so pages can find the marker for a build and the build
     * for a marker.
     *
     * @param  int  $buildId
     * @return string
     */
    public static function keyFor(int $buildId): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $buildId);
    }

    /**
     * Get the build a marker was recorded for, when BuildPusher deployed it.
     *
     * @param  Deployment  $deployment
     * @return int|null
     */
    public static function buildIdOf(Deployment $deployment): ?int
    {
        return $deployment->source === 'deploy' && preg_match('/\A00000000-0000-4000-8000-(\d{12})\z/', $deployment->deployment_key, $match) === 1 ? (int) $match[1] : null;
    }
}
