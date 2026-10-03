<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Accounts\SaveSamlSettings;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateSamlSettingsController
{
    /**
     * Save the account's SAML identity provider, or clear it to go back to OpenID Connect.
     *
     * @param  Account  $account
     * @param  Request  $request
     * @param  User  $user
     * @param  SaveSamlSettings  $save
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, Request $request, #[CurrentUser] User $user, SaveSamlSettings $save): RedirectResponse
    {
        $data = $request->validate([
            'saml_idp_entity_id' => ['nullable', 'string', 'max:500'],
            'saml_idp_sso_url' => ['nullable', 'string', 'max:500'],
            'saml_idp_certificate' => ['nullable', 'string', 'max:20000'],
        ]);
        $save->handle($user, $account, $data['saml_idp_entity_id'] ?? null, $data['saml_idp_sso_url'] ?? null, $data['saml_idp_certificate'] ?? null);

        return to_route('account.security')->with('status', $account->sso_protocol === 'saml' ? __('SAML saved. Test single sign-on before requiring it.') : __('SAML removed; single sign-on uses OpenID Connect.'));
    }
}
