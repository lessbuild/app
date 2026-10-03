<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Enums\ServerType;
use App\Models\Build;
use App\Models\Server;
use App\Models\StorageBucket;
use App\Services\Storage\S3Client;
use Throwable;

/**
 * Decides whether a build runs on its environment's build server, and moves the built release through the
 * environment's storage bucket with presigned URLs, so neither server ever holds the bucket's keys.
 */
class BuildServers
{
    /** Server types that have the runtimes a build needs (PHP, Composer and Node.js). */
    public const array TYPES = [ServerType::App, ServerType::Worker];

    /** How long the presigned URLs work. */
    private const int URL_SECONDS = 7200;

    /**
     * Create a new BuildServers instance.
     *
     * @param  S3Client  $s3  Signs the artifact URLs and deletes artifacts.
     */
    public function __construct(private readonly S3Client $s3) {}

    /**
     * Get the build server and bucket a build should use: the environment's, when both are set, the server is active
     * and isn't the website's own, and the runtime isn't Docker (whose images stay on the server that builds them).
     *
     * @param  Build  $build
     * @return array{server: Server, bucket: StorageBucket}|null
     */
    public function for(Build $build): ?array
    {
        $environment = $build->environment;
        $runtime = $build->environment_payload['runtime']['type'] ?? $environment?->runtime_type;
        if ($environment === null || in_array($runtime, ['docker', 'compose'], true)) {
            return null;
        }
        $server = $environment->buildServer;
        $bucket = $environment->artifactBucket;
        if ($server === null || $bucket === null || $server->provisioning_status !== Server::STATUS_ACTIVE || $server->id === $build->website->server_id) {
            return null;
        }

        return ['server' => $server, 'bucket' => $bucket];
    }

    /**
     * Get where a build's release waits in the bucket.
     *
     * @param  Build  $build
     * @return string
     */
    public function key(Build $build): string
    {
        return "buildpusher-builds/{$build->website->deployment_slug}/{$build->id}.tar.gz";
    }

    /**
     * Get a presigned URL to upload a build's release.
     *
     * @param  StorageBucket  $bucket
     * @param  string  $key
     * @return string
     */
    public function uploadUrl(StorageBucket $bucket, string $key): string
    {
        return $this->s3->presignedUrl($bucket->location(), 'PUT', $key, self::URL_SECONDS);
    }

    /**
     * Get a presigned URL to download a build's release.
     *
     * @param  StorageBucket  $bucket
     * @param  string  $key
     * @return string
     */
    public function downloadUrl(StorageBucket $bucket, string $key): string
    {
        return $this->s3->presignedUrl($bucket->location(), 'GET', $key, self::URL_SECONDS);
    }

    /**
     * Delete a finished build's release from the bucket; a failure only means it stays until the bucket's own
     * lifecycle rules remove it.
     *
     * @param  Build  $build
     * @return void
     */
    public function forget(Build $build): void
    {
        $bucket = $build->environment?->artifactBucket;
        if ($build->artifact_key === null || $bucket === null) {
            return;
        }
        try {
            $this->s3->request($bucket->location(), 'DELETE', $build->artifact_key);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
