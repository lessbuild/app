<?php

declare(strict_types=1);

namespace App\Services\Telemetry;

use App\Data\Telemetry\IssuedIngestToken;
use App\Models\Environment;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/** Issues ingest keys: `bcn_` plus 64 random characters, stored as a SHA-256 hash (public contract from the Monitor app). */
final class IngestTokens
{
    /** Called in a transaction holding the project and environment locks. */
    public function issue(Environment $environment, User $creator, string $name, ?CarbonInterface $expiresAt = null): IssuedIngestToken
    {
        $secret = 'bcn_'.Str::random(64);
        $token = $environment->ingestTokens()->make();
        $token->forceFill([
            'name' => $name,
            'token_hash' => hash('sha256', $secret),
            'prefix' => substr($secret, 0, 12),
            'expires_at' => $expiresAt,
            'created_by' => $creator->id,
        ])->save();
        $token->setRelation('environment', $environment);

        return new IssuedIngestToken($token, $secret);
    }
}
