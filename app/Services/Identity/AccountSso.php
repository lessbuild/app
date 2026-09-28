<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Contracts\Monitoring\DnsResolver;
use App\Models\Account;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Single sign-on for an account through its OpenID Connect identity provider: the authorisation code flow with PKCE and
 * a one-time state kept in the session. The issuer and every endpoint it advertises must be public HTTPS addresses, so
 * a misconfigured issuer can't make us call internal services. Only a verified email the account allows is accepted.
 */
final class AccountSso
{
    /**
     * The session key holding the attempt in progress.
     *
     * @var string
     */
    private const ATTEMPT = 'sso.attempt';

    /**
     * Create a new AccountSso instance.
     *
     * @param  DnsResolver  $dns  Resolves the issuer's hosts, so private addresses can be refused.
     */
    public function __construct(private readonly DnsResolver $dns) {}

    /**
     * Start signing in (or proving it's you) through the account's identity provider, and get the address to send
     * the person to.
     *
     * @param  Session  $session
     * @param  Account  $account
     * @param  string  $intent  "login" to sign in, or "verify" to prove a signed-in person is who the provider says
     * @return string
     */
    public function begin(Session $session, Account $account, string $intent): string
    {
        $metadata = $this->metadata($account);
        $state = Str::random(64);
        $verifier = Str::random(96);
        $session->put(self::ATTEMPT, ['account' => $account->id, 'state' => hash('sha256', $state), 'verifier' => $verifier, 'intent' => $intent]);

        return $metadata['authorization_endpoint'].'?'.http_build_query([
            'client_id' => $account->sso_client_id, 'redirect_uri' => route('sso.callback'), 'response_type' => 'code', 'scope' => 'openid email profile',
            'state' => $state, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Finish the attempt the provider sent the person back from: check the state, swap the code for a token, and read
     * the verified email address.
     *
     * @param  Session  $session
     * @param  string  $code
     * @param  string  $state
     * @return array{account: Account, email: string, intent: string}
     *
     * @throws RuntimeException when anything doesn't check out, with a message safe to show
     */
    public function complete(Session $session, string $code, string $state): array
    {
        $attempt = $session->pull(self::ATTEMPT);
        if (! is_array($attempt) || ! is_string($attempt['state'] ?? null) || ! hash_equals($attempt['state'], hash('sha256', $state))) {
            throw new RuntimeException(__('That sign-in link has expired. Please try again.'));
        }
        $account = Account::query()->find($attempt['account'] ?? null) ?? throw new RuntimeException(__('That account no longer exists.'));
        $metadata = $this->metadata($account);
        $token = Http::asForm()->acceptJson()->timeout(15)->post($metadata['token_endpoint'], [
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => route('sso.callback'),
            'client_id' => $account->sso_client_id, 'client_secret' => $account->sso_client_secret, 'code_verifier' => (string) ($attempt['verifier'] ?? ''),
        ])->json('access_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException(__('The identity provider didn’t sign you in.'));
        }
        $profile = Http::acceptJson()->withToken($token)->timeout(15)->get($metadata['userinfo_endpoint'])->json();
        $email = mb_strtolower(is_array($profile) && is_string($profile['email'] ?? null) ? $profile['email'] : '');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ($profile['email_verified'] ?? true) === false) {
            throw new RuntimeException(__('The identity provider didn’t share a verified email address.'));
        }
        if (! $account->allowsEmail($email)) {
            throw new RuntimeException(__(':account doesn’t allow sign-in from that email domain.', ['account' => $account->name]));
        }

        return ['account' => $account, 'email' => $email, 'intent' => (string) ($attempt['intent'] ?? 'login')];
    }

    /**
     * Mark the session as having signed in through the account's provider.
     *
     * @param  Session  $session
     * @param  Account  $account
     * @return void
     */
    public function markVerified(Session $session, Account $account): void
    {
        $session->put('sso.verified.'.$account->id, true);
    }

    /**
     * Determine whether the session has signed in through the account's provider.
     *
     * @param  Session  $session
     * @param  Account  $account
     * @return bool
     */
    public function verified(Session $session, Account $account): bool
    {
        return $session->get('sso.verified.'.$account->id) === true;
    }

    /**
     * Read the provider's discovery document and check its endpoints.
     *
     * @param  Account  $account
     * @return array{authorization_endpoint: string, token_endpoint: string, userinfo_endpoint: string}
     *
     * @throws RuntimeException when single sign-on isn't set up or the provider isn't usable
     */
    private function metadata(Account $account): array
    {
        if (! $account->hasSso()) {
            throw new RuntimeException(__('Single sign-on isn’t set up for :account.', ['account' => $account->name]));
        }
        $issuer = rtrim((string) $account->sso_issuer, '/');
        $this->assertPublicHttps($issuer);
        $metadata = Http::acceptJson()->timeout(15)->get($issuer.'/.well-known/openid-configuration')->json();
        $endpoints = [];
        foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint'] as $key) {
            $url = is_array($metadata) ? ($metadata[$key] ?? null) : null;
            if (! is_string($url)) {
                throw new RuntimeException(__('The identity provider’s settings are incomplete.'));
            }
            $this->assertPublicHttps($url);
            $endpoints[$key] = $url;
        }

        return $endpoints;
    }

    /**
     * Require an HTTPS URL, without credentials, whose host resolves only to public addresses.
     *
     * @param  string  $url
     * @return void
     *
     * @throws RuntimeException
     */
    private function assertPublicHttps(string $url): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException(__('The identity provider must use a public HTTPS address.'));
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->dns->addresses($host);
        foreach ($addresses === [] ? [''] : $addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException(__('The identity provider must use a public HTTPS address.'));
            }
        }
    }
}
