<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Account;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\Response;
use OneLogin\Saml2\Settings;
use OneLogin\Saml2\Utils;
use RuntimeException;
use Throwable;

/**
 * SAML 2.0 single sign-on for accounts that use it, with OneLogin's toolkit doing the XML and signature work: the
 * sign-in request goes to the identity provider by redirect, and its response must be signed by the provider's
 * certificate, meant for this service and this request, and in date.
 *
 * The provider posts its response cross-site, which browsers send without the (SameSite=Lax) session cookie, so the
 * request is remembered in the cache by its ID, the response is checked there, and the result is handed to a
 * follow-up page with a single-use token; that page has the session and finishes only in the browser that started.
 */
final class AccountSaml
{
    /** Where the browser's attempt in progress is kept in the session. */
    private const ATTEMPT = 'sso.saml.attempt';

    /** How long a sign-in may take, in seconds. */
    private const TTL = 600;

    /**
     * Start a sign-in: remember the request, and return the identity provider URL to send the person to.
     *
     * @param  Session  $session
     * @param  Account  $account
     * @param  string  $intent  "login" or "verify"
     * @return string
     */
    public function begin(Session $session, Account $account, string $intent): string
    {
        $auth = new Auth($this->settings($account));
        $url = $auth->login(null, [], false, false, true);
        $requestId = (string) $auth->getLastRequestID();
        Cache::put('sso.saml.request.'.hash('sha256', $requestId), ['account' => $account->id, 'intent' => $intent], self::TTL);
        $session->put(self::ATTEMPT, $requestId);

        return $url;
    }

    /**
     * Check a posted response against the request it answers (looked up by its InResponseTo) and keep the result for
     * the browser to collect. Returns the single-use token that collects it.
     *
     * @param  string  $samlResponse  the base64 SAMLResponse posted back
     * @return string
     *
     * @throws RuntimeException when anything doesn't check out, with a message safe to show
     */
    public function consume(string $samlResponse): string
    {
        $xml = base64_decode($samlResponse, true);
        $requestId = is_string($xml) && preg_match('/InResponseTo="([A-Za-z0-9_.:-]{1,256})"/', $xml, $match) === 1 ? $match[1] : null;
        $attempt = $requestId === null ? null : Cache::pull('sso.saml.request.'.hash('sha256', $requestId));
        if (! is_array($attempt)) {
            throw new RuntimeException(__('That sign-in has expired. Please try again.'));
        }
        $account = Account::query()->whereKey((string) ($attempt['account'] ?? ''))->first() ?? throw new RuntimeException(__('That account no longer exists.'));
        $settings = new Settings($this->settings($account));

        $server = $_SERVER;
        try {
            // The toolkit compares the response's destination with "this request's URL", which it reads from the
            // server variables; behind Laravel's front controller those don't name the ACS, so name it for the check.
            Utils::setBaseURL(rtrim(url('/'), '/').'/');
            $_SERVER['REQUEST_URI'] = (string) parse_url(route('sso.saml.acs'), PHP_URL_PATH);
            unset($_SERVER['QUERY_STRING']);
            $response = new Response($settings, $samlResponse);
            $valid = $response->isValid($requestId);
            $email = mb_strtolower(trim((string) $response->getNameId()));
            $error = $response->getError();
        } catch (Throwable $exception) {
            report($exception);
            throw new RuntimeException(__('The identity provider’s response couldn’t be read.'));
        } finally {
            $_SERVER = $server;
            Utils::setBaseURL('');
        }
        if (! $valid) {
            throw new RuntimeException(__('The identity provider’s response didn’t check out: :reason', ['reason' => (string) $error]));
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException(__('The identity provider didn’t share an email address as the name ID.'));
        }
        if (! $account->allowsEmail($email)) {
            throw new RuntimeException(__(':account doesn’t allow sign-in from that email domain.', ['account' => $account->name]));
        }
        $token = Str::random(64);
        Cache::put('sso.saml.result.'.hash('sha256', $token), ['account' => $account->id, 'email' => $email, 'intent' => (string) ($attempt['intent'] ?? 'login'), 'request' => $requestId], 120);

        return $token;
    }

    /**
     * Collect a checked response in the browser that started the sign-in.
     *
     * @param  Session  $session
     * @param  string  $token
     * @return array{account: Account, email: string, intent: string}
     *
     * @throws RuntimeException when the token is unknown, used, or from another browser
     */
    public function finish(Session $session, string $token): array
    {
        $result = Cache::pull('sso.saml.result.'.hash('sha256', $token));
        $requestId = $session->pull(self::ATTEMPT);
        if (! is_array($result) || ! is_string($requestId) || ! hash_equals((string) ($result['request'] ?? ''), $requestId)) {
            throw new RuntimeException(__('That sign-in has expired or was started in another browser. Please try again.'));
        }
        $account = Account::query()->whereKey((string) $result['account'])->first() ?? throw new RuntimeException(__('That account no longer exists.'));

        return ['account' => $account, 'email' => (string) $result['email'], 'intent' => (string) $result['intent']];
    }

    /**
     * Get this service's SAML metadata for the account, to give to the identity provider.
     *
     * @param  Account  $account
     * @return string
     */
    public function metadata(Account $account): string
    {
        $settings = new Settings($this->settings($account, requireIdp: false), true);

        return $settings->getSPMetadata();
    }

    /**
     * Get the entity ID the account's identity provider knows this service by.
     *
     * @param  Account  $account
     * @return string
     */
    public function entityId(Account $account): string
    {
        return route('sso.saml.metadata', $account->id);
    }

    /**
     * Build the toolkit's settings: strict checking, signed assertions required, email-address name IDs.
     *
     * @param  Account  $account
     * @param  bool  $requireIdp  whether the identity provider must be configured (metadata doesn't need it)
     * @return array<string, mixed>
     */
    private function settings(Account $account, bool $requireIdp = true): array
    {
        if ($requireIdp && ($account->sso_protocol !== 'saml' || ! $account->hasSso())) {
            throw new RuntimeException(__('Single sign-on isn’t set up for :account.', ['account' => $account->name]));
        }

        return [
            'strict' => true,
            'debug' => false,
            'sp' => [
                'entityId' => $this->entityId($account),
                'assertionConsumerService' => ['url' => route('sso.saml.acs'), 'binding' => Constants::BINDING_HTTP_POST],
                'NameIDFormat' => Constants::NAMEID_EMAIL_ADDRESS,
            ],
            'idp' => [
                'entityId' => (string) ($account->saml_idp_entity_id ?? 'unset'),
                'singleSignOnService' => ['url' => (string) ($account->saml_idp_sso_url ?? 'https://unset.invalid/'), 'binding' => Constants::BINDING_HTTP_REDIRECT],
                'x509cert' => (string) ($account->saml_idp_certificate ?? ''),
            ],
            'security' => [
                'wantAssertionsSigned' => true,
                'wantMessagesSigned' => false,
                'wantNameId' => true,
                'requestedAuthnContext' => false,
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
                'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
                'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
            ],
        ];
    }
}
