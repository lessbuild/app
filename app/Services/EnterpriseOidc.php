<?php

namespace App\Services;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class EnterpriseOidc
{
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

    private function configuration(Organization $organization): array
    {
        $configuration = $organization->sso_configuration ?? [];
        if (! filled($configuration['issuer'] ?? null) || ! filled($configuration['client_id'] ?? null) || ! filled($configuration['client_secret'] ?? null)) {
            throw new RuntimeException('Workspace SSO is not fully configured.');
        }

        return $configuration;
    }

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

    private function assertPublicHttps(string $url): void
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';
        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('The SSO issuer must be a public HTTPS URL.');
        }
        $records = dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $addresses = collect($records)->map(fn ($record) => $record['ip'] ?? $record['ipv6'] ?? null)->filter();
        if ($addresses->isEmpty() || $addresses->contains(fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false)) {
            throw new RuntimeException('The SSO issuer must resolve only to public addresses.');
        }
    }
}
