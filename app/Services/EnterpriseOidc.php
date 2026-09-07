<?php

namespace App\Services;

use App\Models\Organization;
use App\Support\PublicIpAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class EnterpriseOidc
{
    /**
     * Begin a workspace SSO attempt with session-bound state and an S256 PKCE challenge.
     *
     * @param  Request  $request  The current browser request whose session stores the attempt.
     * @param  Organization  $organization  The workspace supplying the identity-provider configuration.
     * @return string The discovered authorization URL with client, callback, state and PKCE parameters.
     */
    public function authorizationUrl(Request $request, Organization $organization): string
    {
        $configuration = $this->configuration($organization);
        $metadata = $this->metadata($configuration['issuer']);
        $state = Str::random(64);
        $verifier = Str::random(96);
        $request->session()->put('oidc.'.$organization->id, ['state' => hash('sha256', $state), 'verifier' => $verifier]);

        return $metadata['authorization_endpoint'].'?'.http_build_query([
            'client_id' => $configuration['client_id'], 'redirect_uri' => route('organizations.sso.callback'),
            'response_type' => 'code', 'scope' => 'openid email profile', 'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Consume the SSO state, exchange the code and match userinfo to the signed-in account.
     *
     * @param  Request  $request  The callback request containing state/code and the authenticated user.
     * @param  Organization  $organization  The workspace supplying SSO configuration and allowed email domains.
     * @return void No value; records the workspace verification timestamp only after all checks pass.
     *
     * @throws RuntimeException If state, provider profile, email or domain verification fails.
     */
    public function verify(Request $request, Organization $organization): void
    {
        $attempt = $request->session()->pull('oidc.'.$organization->id);
        if (! is_array($attempt) || ! hash_equals((string) ($attempt['state'] ?? ''), hash('sha256', (string) $request->query('state')))) {
            throw new RuntimeException('The SSO state could not be verified.');
        }
        $configuration = $this->configuration($organization);
        $metadata = $this->metadata($configuration['issuer']);
        $token = Http::asForm()->acceptJson()->timeout(15)->post($metadata['token_endpoint'], [
            'grant_type' => 'authorization_code', 'code' => (string) $request->query('code'),
            'redirect_uri' => route('organizations.sso.callback'), 'client_id' => $configuration['client_id'],
            'client_secret' => $configuration['client_secret'], 'code_verifier' => $attempt['verifier'],
        ])->throw()->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('The identity provider did not issue an access token.');
        }
        $profile = Http::acceptJson()->withToken($token)->timeout(15)->get($metadata['userinfo_endpoint'])->throw()->json();
        $email = Str::lower((string) ($profile['email'] ?? ''));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! hash_equals(Str::lower($request->user()->email), $email) || ($profile['email_verified'] ?? true) === false) {
            throw new RuntimeException('The SSO identity does not match your verified BuildPusher email.');
        }
        $domains = $organization->allowed_email_domains ?? [];
        if ($domains !== [] && ! in_array(Str::afterLast($email, '@'), $domains, true)) {
            throw new RuntimeException('The SSO email domain is not allowed.');
        }
        $request->session()->put('organization_sso_verified.'.$organization->id, time());
    }

    /**
     * Require the workspace's issuer and client credentials before an SSO exchange.
     *
     * @param  Organization  $organization  The workspace whose SSO settings are read.
     * @return array<string, mixed> The saved SSO configuration including issuer, client ID and secret.
     *
     * @throws RuntimeException If required settings are absent.
     */
    private function configuration(Organization $organization): array
    {
        $configuration = $organization->sso_configuration ?? [];
        if (! filled($configuration['issuer'] ?? null) || ! filled($configuration['client_id'] ?? null) || ! filled($configuration['client_secret'] ?? null)) {
            throw new RuntimeException('Workspace SSO is not fully configured.');
        }

        return $configuration;
    }

    /**
     * Discover the issuer's authorization, token and userinfo endpoints and validate their addresses.
     *
     * @param  string  $issuer  The configured issuer base URL.
     * @return array<string, mixed> The provider metadata with required public HTTPS endpoints.
     *
     * @throws RuntimeException If an endpoint is missing, insecure or resolves to a nonpublic address.
     */
    private function metadata(string $issuer): array
    {
        $issuer = rtrim($issuer, '/');
        $this->assertPublicHttps($issuer);
        $metadata = Http::acceptJson()->timeout(15)->get($issuer.'/.well-known/openid-configuration')->throw()->json();
        foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint'] as $key) {
            if (! is_string($metadata[$key] ?? null) || ! str_starts_with($metadata[$key], 'https://')) {
                throw new RuntimeException('The identity provider metadata is incomplete.');
            }
            $this->assertPublicHttps($metadata[$key]);
        }

        return $metadata;
    }

    /**
     * Validate HTTPS syntax and require every resolved endpoint address to be public.
     *
     * @param  string  $url  The issuer or discovered endpoint URL to validate.
     * @return void No value when the URL and DNS records pass validation.
     *
     * @throws RuntimeException If HTTPS, host, userinfo or public-address validation fails.
     */
    private function assertPublicHttps(string $url): void
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('The SSO issuer must be a public HTTPS URL.');
        }
        $records = dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $addresses = collect($records)->map(fn ($record) => $record['ip'] ?? $record['ipv6'] ?? null)->filter();
        if ($addresses->isEmpty() || $addresses->contains(fn (string $ip): bool => ! PublicIpAddress::isValid($ip))) {
            throw new RuntimeException('The SSO issuer must resolve only to public addresses.');
        }
    }
}
