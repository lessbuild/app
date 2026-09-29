<x-signal.layouts.account :account="$account" :title="__('Security')" :description="__('Rules everyone in :account must follow, and single sign-on through your identity provider.', ['account' => $account->name])">
    @if (session('status'))<x-signal.ui.alert tone="success" role="status">{{ session('status') }}</x-signal.ui.alert>@endif
    @if (session('error'))<x-signal.ui.alert tone="danger" role="alert">{{ session('error') }}</x-signal.ui.alert>@endif

    <form method="POST" action="{{ route('account.security.update') }}" class="grid gap-6">
        @csrf
        @method('PUT')
        <x-signal.ui.settings-section :title="__('Sign-in rules')" :description="__('Checked on every request. You can’t save a rule that would lock you out.')">
            <div class="grid gap-5 p-4 sm:p-6">
                <x-signal.ui.checkbox name="require_two_factor" :checked="$account->require_two_factor" unchecked-value="0" :description="__('Members without two-factor authentication or a passkey are sent to set one up before they can do anything else.')">{{ __('Require two-factor authentication') }}</x-signal.ui.checkbox>
                <x-signal.ui.input-field name="session_idle_minutes" type="number" min="5" max="10080" :label="__('Sign people out after (minutes without activity)')" :value="$account->session_idle_minutes" :description="__('Leave empty to keep people signed in.')" />
                <x-signal.ui.textarea-field name="allowed_email_domains" rows="3" :label="__('Allowed email domains')" :value="implode(PHP_EOL, $account->allowed_email_domains ?? [])" :description="__('One per line, e.g. acme.com. Only these addresses can be invited or sign in with single sign-on. Leave empty to allow any.')" />
                <x-signal.ui.textarea-field name="allowed_ip_ranges" rows="3" :label="__('Allowed IP addresses and ranges')" :value="implode(PHP_EOL, $account->allowed_ip_ranges ?? [])" :description="__('One per line, e.g. 203.0.113.0/24 or 2001:db8::/32. Leave empty to allow any. You’re connecting from :ip.', ['ip' => $ip])" />
            </div>
        </x-signal.ui.settings-section>

        <x-signal.ui.settings-section :title="__('Single sign-on')" :description="__('Let members sign in through your identity provider (Okta, Microsoft Entra ID, Google Workspace, Auth0 or any OpenID Connect provider).')">
            <div class="grid gap-5 p-4 sm:p-6">
                <div class="rounded-control border border-line bg-surface-muted p-3 text-sm">
                    <p class="font-semibold text-ink">{{ __('Redirect URI for your provider') }}</p>
                    <p class="mt-1 break-all font-mono text-xs text-muted">{{ route('sso.callback') }}</p>
                </div>
                <x-signal.ui.input-field name="sso_issuer" type="url" :label="__('Issuer URL')" :value="$account->sso_issuer" placeholder="https://login.example.com" :description="__('Its /.well-known/openid-configuration must be reachable over public HTTPS.')" />
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-signal.ui.input-field name="sso_client_id" :label="__('Client ID')" :value="$account->sso_client_id" autocomplete="off" />
                    <x-signal.ui.input-field name="sso_client_secret" type="password" :label="__('Client secret')" autocomplete="new-password" :restore="false" :placeholder="$account->sso_client_secret ? __('Saved — leave empty to keep it') : ''" />
                </div>
                <x-signal.ui.checkbox name="sso_enforced" :checked="$account->sso_enforced" unchecked-value="0" :description="__('Members must sign in through your provider once per session. Test it first.')">{{ __('Require single sign-on') }}</x-signal.ui.checkbox>
            </div>
        </x-signal.ui.settings-section>

        <div class="flex flex-wrap items-center gap-3">
            <x-signal.ui.button type="submit" variant="primary">{{ __('Save security settings') }}</x-signal.ui.button>
            @if ($account->hasSso())
                <x-signal.ui.button :href="route('sso.verify')" variant="secondary">{{ __('Test single sign-on') }}</x-signal.ui.button>
                <span @class(['text-sm', 'text-success' => $ssoVerified, 'text-muted' => ! $ssoVerified])>{{ $ssoVerified ? __('You’ve signed in through your provider this session.') : __('Not tested this session.') }}</span>
            @endif
        </div>
    </form>

    <x-signal.ui.settings-section id="saml" :title="__('SAML single sign-on')" :description="__('Use SAML 2.0 instead of OpenID Connect (for ADFS, Okta SAML, OneLogin, JumpCloud and others). Saving a provider switches single sign-on to SAML; clearing the fields switches back.')">
        <div class="grid gap-5 p-4 sm:p-6">
            <div class="grid gap-2 rounded-control border border-line bg-surface-muted p-3 text-sm">
                <p><span class="font-semibold text-ink">{{ __('Entity ID / metadata URL') }}</span><span class="mt-1 block break-all font-mono text-xs text-muted">{{ route('sso.saml.metadata', $account->id) }}</span></p>
                <p><span class="font-semibold text-ink">{{ __('Assertion consumer service (ACS) URL') }}</span><span class="mt-1 block break-all font-mono text-xs text-muted">{{ route('sso.saml.acs') }}</span></p>
                <p class="text-xs text-muted">{{ __('Send the email address as the name ID, and sign assertions.') }}</p>
            </div>
            <form method="POST" action="{{ route('account.security.saml') }}" class="grid gap-5">
                @csrf
                @method('PUT')
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-signal.ui.input-field name="saml_idp_entity_id" :label="__('Identity provider entity ID')" :value="$account->saml_idp_entity_id" maxlength="500" />
                    <x-signal.ui.input-field name="saml_idp_sso_url" type="url" :label="__('Identity provider SSO URL')" :value="$account->saml_idp_sso_url" maxlength="500" placeholder="https://idp.example.com/sso/saml" />
                </div>
                <x-signal.ui.textarea-field name="saml_idp_certificate" :label="__('Identity provider signing certificate')" :value="$account->saml_idp_certificate" rows="5" :description="__('The X.509 certificate (PEM) it signs assertions with.')" />
                <div class="flex flex-wrap items-center gap-3">
                    <x-signal.ui.button type="submit" variant="secondary">{{ __('Save SAML') }}</x-signal.ui.button>
                    @if ($account->sso_protocol === 'saml')<x-signal.ui.badge tone="success">{{ __('In use') }}</x-signal.ui.badge>@endif
                </div>
            </form>
        </div>
    </x-signal.ui.settings-section>
</x-signal.layouts.account>
