<?php

namespace App\Services;

use App\Models\EnvironmentResource;
use Illuminate\Support\Str;

class PreviewResourceCredentials
{
    /**
     * Resolve the credential boundary for a preview-owned Valkey resource.
     *
     * New preview resources receive a random password. An existing preview resource
     * preserves its current password, including legacy passwordless state, so an
     * already-running container and application snapshot do not become inconsistent
     * during an ordinary revision update.
     *
     * @param  EnvironmentResource|null  $existing  Existing resource declaration, if any.
     * @return array<string, string> Managed variables to merge into the encrypted resource configuration.
     */
    public function valkey(?EnvironmentResource $existing): array
    {
        if ($existing) {
            $password = $existing->configuration['variables']['REDIS_PASSWORD'] ?? null;

            return is_string($password) && $password !== ''
                ? ['REDIS_PASSWORD' => $password]
                : [];
        }

        return ['REDIS_PASSWORD' => Str::random(48)];
    }
}
