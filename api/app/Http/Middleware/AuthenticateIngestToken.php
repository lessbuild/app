<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\IngestToken;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateIngestToken
{
    /**
     * Authenticate telemetry with an ingest key from the bearer token or `X-Beacon-Token`. The key must be active and
     * its project must have Monitoring on. `last_used_at` is written at most once a minute, so busy keys don't cause a
     * write per request.
     *
     * @param  Request  $request
     * @param  Closure(Request): Response  $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = $request->bearerToken() ?? $request->header('X-Beacon-Token');

        if (! is_string($secret) || $secret === '' || strlen($secret) > 256) {
            return response()->json(['message' => 'An ingestion token is required.'], Response::HTTP_UNAUTHORIZED);
        }

        $token = IngestToken::active()
            ->with('environment.project')
            ->where('token_hash', hash('sha256', $secret))
            ->whereHas('environment.project.enabledServices', fn (Builder $services) => $services->where('service', 'monitoring'))
            ->first();

        if ($token === null) {
            return response()->json(['message' => 'The ingestion token is invalid.'], Response::HTTP_UNAUTHORIZED);
        }

        IngestToken::query()->whereKey($token->id)
            ->where(fn (Builder $used) => $used->whereNull('last_used_at')->orWhere('last_used_at', '<', now()->subMinute()))
            ->update(['last_used_at' => now()]);
        $request->attributes->set('ingest_environment', $token->environment);
        $request->attributes->set('ingest_token', $token);

        return $next($request);
    }
}
