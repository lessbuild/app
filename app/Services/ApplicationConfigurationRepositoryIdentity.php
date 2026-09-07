<?php

namespace App\Services;

use App\Models\Repository;

class ApplicationConfigurationRepositoryIdentity
{
    /**
     * Derive the keyed identity used to deduplicate equivalent deployment intents.
     *
     * @param  string  $repositoryFingerprint  The captured source/deployment identity of the repository.
     * @param  array{environment_payload: mixed, status: string}  $attributes  Captured environment payload and initial build status; other attributes do not affect the digest.
     * @return string A SHA-256 HMAC covering source identity, payload and status.
     *
     * @throws \JsonException If the captured values cannot be encoded.
     */
    public static function intentDigest(string $repositoryFingerprint, array $attributes): string
    {
        return hash_hmac('sha256', json_encode([
            $repositoryFingerprint, $attributes['environment_payload'], $attributes['status'],
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** Hash deployment inputs, excluding mutable webhook receipts and lifecycle bookkeeping. */
    public static function fingerprint(Repository $repository): string
    {
        $repository->loadMissing('website');

        return hash_hmac('sha256', json_encode([
            $repository->only([
                'id', 'organization_id', 'user_id', 'provider_id', 'website_id',
                'url', 'branch', 'build_commands', 'post_deployment_commands',
            ]),
            ['website_id' => $repository->website?->id, 'server_id' => $repository->website?->server_id,
                'deployment_slug' => $repository->website?->deployment_slug,
                'base_environment' => (string) $repository->website?->environment],
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
