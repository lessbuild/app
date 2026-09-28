<?php

declare(strict_types=1);

namespace App\Services\Deploy\Configuration;

use App\Models\Repository;

/** Keyed fingerprints that tell whether a repository or a deploy intent changed since a review, without exposing either. */
final class ConfigurationIdentity
{
    /**
     * A keyed fingerprint of the repository settings a deploy depends on, including its website's directory and server.
     *
     * @param  Repository  $repository
     * @return string
     */
    public static function repository(Repository $repository): string
    {
        return hash_hmac('sha256', json_encode([
            $repository->only(['id', 'project_id', 'provider_id', 'website_id', 'url', 'branch', 'deployment_root', 'build_commands', 'post_deployment_commands']),
            ['deployment_slug' => $repository->website->deployment_slug, 'server_id' => $repository->website->server_id],
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /**
     * A keyed fingerprint of a deploy's intent: the repository's fingerprint and the payload it would deploy.
     *
     * @param  string  $repositoryFingerprint
     * @param  array<string, mixed>  $payload  the build payload the environment produces now
     * @return string
     */
    public static function intent(string $repositoryFingerprint, array $payload): string
    {
        return hash_hmac('sha256', json_encode([$repositoryFingerprint, $payload], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
