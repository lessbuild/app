<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Services\Identity\AccountSso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `GET /api/app/account/security`. */
final class ShowAccountSecurityController
{
    /**
     * Return the account's sign-in rules and single sign-on settings (secrets only as whether they're set), the
     * addresses to give an identity provider, and whether the viewer has tested single sign-on this session.
     *
     * @param  Account  $account
     * @param  Request  $request
     * @param  AccountSso  $sso
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, Request $request, AccountSso $sso): JsonResponse
    {
        return response()->json([
            'account' => ['id' => $account->id, 'name' => $account->name],
            'rules' => [
                'requireTwoFactor' => (bool) $account->require_two_factor,
                'sessionIdleMinutes' => $account->session_idle_minutes,
                'allowedEmailDomains' => array_values($account->allowed_email_domains ?? []),
                'allowedIpRanges' => array_values($account->allowed_ip_ranges ?? []),
            ],
            'oidc' => [
                'issuer' => $account->sso_issuer,
                'clientId' => $account->sso_client_id,
                'hasClientSecret' => $account->sso_client_secret !== null && $account->sso_client_secret !== '',
                'enforced' => (bool) $account->sso_enforced,
                'redirectUri' => route('sso.callback'),
            ],
            'saml' => [
                'entityId' => $account->saml_idp_entity_id,
                'ssoUrl' => $account->saml_idp_sso_url,
                'certificate' => $account->saml_idp_certificate,
                'inUse' => $account->sso_protocol === 'saml',
                'metadataUrl' => route('sso.saml.metadata', $account->id),
                'acsUrl' => route('sso.saml.acs'),
            ],
            'scim' => [
                'on' => $account->scim_token_hash !== null,
                'defaultRole' => $account->scim_default_role ?? 'member',
                'baseUrl' => url('/api/scim/v2'),
            ],
            'hasSso' => $account->hasSso(),
            'ssoVerified' => $sso->verified($request->session(), $account),
            'ip' => (string) $request->ip(),
        ]);
    }
}
