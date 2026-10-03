<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The platform's Google OAuth client (services.google_search_console), shared by Search Console and the Google
 * Analytics import: sign-in URLs, code exchange, and API requests with a cached access token.
 */
final class GoogleOAuth
{
    /**
     * Determine whether the platform has Google OAuth credentials.
     *
     * @return bool
     */
    public function configured(): bool
    {
        return filled(config('services.google_search_console.client_id')) && filled(config('services.google_search_console.client_secret'));
    }

    /**
     * Get the Google sign-in URL asking for one scope, offline so a refresh token comes back.
     *
     * @param  string  $scope
     * @param  string  $redirect  the callback address registered with Google
     * @param  string  $state
     * @return string
     */
    public function authorizationUrl(string $scope, string $redirect, string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google_search_console.client_id'),
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => $scope,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /**
     * Swap the code Google sent back for a refresh token.
     *
     * @param  string  $code
     * @param  string  $redirect  the same callback address as the sign-in URL
     * @return string
     */
    public function exchange(string $code, string $redirect): string
    {
        $refresh = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirect])->json('refresh_token');
        if (! is_string($refresh) || $refresh === '') {
            throw new RuntimeException(__('Google didn’t grant offline access. Try connecting again.'));
        }

        return $refresh;
    }

    /**
     * Start a request to a Google API with a fresh access token (kept for 50 minutes per refresh token).
     *
     * @param  string  $refreshToken
     * @param  string  $reconnect  what to tell the person when Google no longer accepts the connection
     * @return PendingRequest
     */
    public function client(string $refreshToken, string $reconnect): PendingRequest
    {
        $access = Cache::remember('google.access.'.hash('sha256', $refreshToken), 3000, function () use ($refreshToken, $reconnect): string {
            $token = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken])->json('access_token');
            if (! is_string($token) || $token === '') {
                throw new RuntimeException($reconnect);
            }

            return $token;
        });

        return Http::withToken($access)->acceptJson()->timeout(30);
    }

    /**
     * Turn an error response from Google into an exception with its message.
     *
     * @param  Response  $response
     * @return void
     */
    public function check(Response $response): void
    {
        if ($response->failed()) {
            $message = $response->json('error.message') ?? $response->json('error_description') ?? $response->json('error');
            throw new RuntimeException(__('Google answered: :message', ['message' => is_string($message) ? $message : 'HTTP '.$response->status()]));
        }
    }

    /**
     * Call Google's token endpoint with the platform's client credentials.
     *
     * @param  array<string, string>  $parameters
     * @return Response
     */
    private function token(array $parameters): Response
    {
        $response = Http::asForm()->acceptJson()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google_search_console.client_id'),
            'client_secret' => config('services.google_search_console.client_secret'),
            ...$parameters,
        ]);
        $this->check($response);

        return $response;
    }
}
