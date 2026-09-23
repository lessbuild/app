<?php

namespace App\Modules\Monitor\Http\Middleware;

use App\Modules\Monitor\Models\IngestToken;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIngestToken
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = $request->bearerToken() ?? $request->header('X-Beacon-Token');

        if (! is_string($secret) || $secret === '' || strlen($secret) > 256) {
            return response()->json(['message' => 'An ingestion token is required.'], Response::HTTP_UNAUTHORIZED);
        }

        $token = IngestToken::active()
            ->with('environment.application')
            ->where('token_hash', hash('sha256', $secret))
            ->whereHas('environment', fn (Builder $environment) => $environment->where('status', 'active')->whereHas('application'))
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
