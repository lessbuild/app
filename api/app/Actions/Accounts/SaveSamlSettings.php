<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SaveSamlSettings
{
    /**
     * Switch the account's single sign-on to SAML with an identity provider's entity ID, sign-in URL and signing
     * certificate, or back to OpenID Connect by clearing them. Required SSO is turned off when switching, so the new
     * setup can be tested first.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @param  string|null  $entityId
     * @param  string|null  $ssoUrl
     * @param  string|null  $certificate  PEM, with or without the BEGIN/END lines
     * @return void
     */
    public function handle(User $actor, Account $account, ?string $entityId, ?string $ssoUrl, ?string $certificate): void
    {
        Gate::forUser($actor)->authorize('update', $account);
        if (blank($entityId) && blank($ssoUrl) && blank($certificate)) {
            $account->forceFill(['sso_protocol' => 'oidc', 'saml_idp_entity_id' => null, 'saml_idp_sso_url' => null, 'saml_idp_certificate' => null, 'sso_enforced' => $account->sso_protocol === 'saml' ? false : $account->sso_enforced])->save();

            return;
        }
        if (! is_string($ssoUrl) || ! str_starts_with($ssoUrl, 'https://') || filter_var($ssoUrl, FILTER_VALIDATE_URL) === false) {
            throw ValidationException::withMessages(['saml_idp_sso_url' => __('Enter the identity provider’s HTTPS single sign-on URL.')]);
        }
        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', (string) $certificate) ?? '';
        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split($body, 64, "\n")."-----END CERTIFICATE-----\n";
        if ($body === '' || @openssl_x509_read($pem) === false) {
            throw ValidationException::withMessages(['saml_idp_certificate' => __('Paste the identity provider’s X.509 signing certificate.')]);
        }
        $changed = $account->sso_protocol !== 'saml' || $account->saml_idp_entity_id !== trim((string) $entityId) || $account->saml_idp_sso_url !== $ssoUrl || $account->saml_idp_certificate !== $pem;
        $account->forceFill([
            'sso_protocol' => 'saml', 'saml_idp_entity_id' => trim((string) $entityId), 'saml_idp_sso_url' => $ssoUrl, 'saml_idp_certificate' => $pem,
            'sso_enforced' => $changed ? false : $account->sso_enforced,
        ])->save();
    }
}
