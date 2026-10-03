<script setup lang="ts">
/**
 * The account's security: sign-in rules everyone must follow, single sign-on (OpenID Connect or SAML) through the
 * company's identity provider, and SCIM provisioning. Saving any of them asks the person to confirm it's them.
 */
definePageMeta({ layout: 'app', area: 'account' });
const { t } = useT();
type Security = {
    account: { id: string; name: string };
    rules: { requireTwoFactor: boolean; sessionIdleMinutes: number | null; allowedEmailDomains: string[]; allowedIpRanges: string[] };
    oidc: { issuer: string | null; clientId: string | null; hasClientSecret: boolean; enforced: boolean; redirectUri: string };
    saml: { entityId: string | null; ssoUrl: string | null; certificate: string | null; inUse: boolean; metadataUrl: string; acsUrl: string };
    scim: { on: boolean; defaultRole: string; baseUrl: string };
    hasSso: boolean;
    ssoVerified: boolean;
    ip: string;
};
const { data } = await useApi<Security>('/account/security');
const scimToken = ref<string | null>(null);
const roles = computed(() => [{ value: 'viewer', label: t('Viewer') }, { value: 'member', label: t('Member') }, { value: 'admin', label: t('Admin') }]);
const scimRole = ref(data.value.scim.defaultRole);

/** Keep a new SCIM token to show once. */
function scim(result: Record<string, unknown>): null {
    scimToken.value = typeof result.token === 'string' ? result.token : null;
    refreshPage();
    return null;
}
</script>

<template>
    <div class="space-y-10">
        <PageHeader :eyebrow="data.account.name" :title="t('Security')" :description="t('Rules everyone in :account must follow, and single sign-on through your identity provider.', { account: data.account.name })" />

        <ApiForm action="/api/app/account/security" method="PUT" class="!gap-10">
            <SettingsSection :title="t('Sign-in rules')" :description="t('Checked on every request. You can’t save a rule that would lock you out.')">
                <div class="grid gap-5 p-4 sm:p-6">
                    <CheckboxField name="require_two_factor" unchecked-value="0" :checked="data.rules.requireTwoFactor" :label="t('Require two-factor authentication')" :description="t('Members without two-factor authentication or a passkey are sent to set one up before they can do anything else.')" />
                    <InputField name="session_idle_minutes" type="number" min="5" max="10080" :label="t('Sign people out after (minutes without activity)')" :model-value="data.rules.sessionIdleMinutes === null ? '' : String(data.rules.sessionIdleMinutes)" :description="t('Leave empty to keep people signed in.')" />
                    <TextareaField name="allowed_email_domains" rows="3" :label="t('Allowed email domains')" :model-value="data.rules.allowedEmailDomains.join('\n')" :description="t('One per line, e.g. acme.com. Only these addresses can be invited or sign in with single sign-on. Leave empty to allow any.')" />
                    <TextareaField name="allowed_ip_ranges" rows="3" :label="t('Allowed IP addresses and ranges')" :model-value="data.rules.allowedIpRanges.join('\n')" :description="t('One per line, e.g. 203.0.113.0/24 or 2001:db8::/32. Leave empty to allow any. You’re connecting from :ip.', { ip: data.ip })" />
                </div>
            </SettingsSection>

            <SettingsSection :title="t('Single sign-on')" :description="t('Let members sign in through your identity provider (Okta, Microsoft Entra ID, Google Workspace, Auth0 or any OpenID Connect provider).')">
                <div class="grid gap-5 p-4 sm:p-6">
                    <div class="rounded-control border border-line bg-surface-muted p-3 text-sm">
                        <p class="font-semibold text-ink">{{ t('Redirect URI for your provider') }}</p>
                        <p class="mt-1 break-all font-mono text-xs text-muted">{{ data.oidc.redirectUri }}</p>
                    </div>
                    <InputField name="sso_issuer" type="url" :label="t('Issuer URL')" :model-value="data.oidc.issuer ?? ''" placeholder="https://login.example.com" :description="t('Its /.well-known/openid-configuration must be reachable over public HTTPS.')" />
                    <div class="grid gap-5 sm:grid-cols-2">
                        <InputField name="sso_client_id" :label="t('Client ID')" :model-value="data.oidc.clientId ?? ''" autocomplete="off" />
                        <InputField name="sso_client_secret" type="password" :label="t('Client secret')" autocomplete="new-password" :placeholder="data.oidc.hasClientSecret ? t('Saved — leave empty to keep it') : ''" />
                    </div>
                    <CheckboxField name="sso_enforced" unchecked-value="0" :checked="data.oidc.enforced" :label="t('Require single sign-on')" :description="t('Members must sign in through your provider once per session. Test it first.')" />
                </div>
            </SettingsSection>

            <div class="flex flex-wrap items-center gap-3">
                <SubmitButton>{{ t('Save security settings') }}</SubmitButton>
                <template v-if="data.hasSso">
                    <a href="/sso/verify" class="ui-btn ui-btn-secondary">{{ t('Test single sign-on') }}</a>
                    <span :class="['text-sm', data.ssoVerified ? 'text-success' : 'text-muted']">{{ data.ssoVerified ? t('You’ve signed in through your provider this session.') : t('Not tested this session.') }}</span>
                </template>
            </div>
        </ApiForm>

        <SettingsSection id="saml" :title="t('SAML single sign-on')" :description="t('Use SAML 2.0 instead of OpenID Connect (for ADFS, Okta SAML, OneLogin, JumpCloud and others). Saving a provider switches single sign-on to SAML; clearing the fields switches back.')">
            <div class="grid gap-5 p-4 sm:p-6">
                <div class="grid gap-2 rounded-control border border-line bg-surface-muted p-3 text-sm">
                    <p><span class="font-semibold text-ink">{{ t('Entity ID / metadata URL') }}</span><span class="mt-1 block break-all font-mono text-xs text-muted">{{ data.saml.metadataUrl }}</span></p>
                    <p><span class="font-semibold text-ink">{{ t('Assertion consumer service (ACS) URL') }}</span><span class="mt-1 block break-all font-mono text-xs text-muted">{{ data.saml.acsUrl }}</span></p>
                    <p class="text-xs text-muted">{{ t('Send the email address as the name ID, and sign assertions.') }}</p>
                </div>
                <ApiForm action="/api/app/account/security/saml" method="PUT">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <InputField name="saml_idp_entity_id" :label="t('Identity provider entity ID')" :model-value="data.saml.entityId ?? ''" maxlength="500" />
                        <InputField name="saml_idp_sso_url" type="url" :label="t('Identity provider SSO URL')" :model-value="data.saml.ssoUrl ?? ''" maxlength="500" placeholder="https://idp.example.com/sso/saml" />
                    </div>
                    <TextareaField name="saml_idp_certificate" :label="t('Identity provider signing certificate')" :model-value="data.saml.certificate ?? ''" rows="5" :description="t('The X.509 certificate (PEM) it signs assertions with.')" />
                    <div class="flex flex-wrap items-center gap-3">
                        <SubmitButton variant="secondary">{{ t('Save SAML') }}</SubmitButton>
                        <Badge v-if="data.saml.inUse" tone="success">{{ t('In use') }}</Badge>
                    </div>
                </ApiForm>
            </div>
        </SettingsSection>

        <SettingsSection id="scim" :title="t('SCIM provisioning')" :description="t('Let Okta or Microsoft Entra ID add people when they’re assigned the app and remove them when they’re unassigned or leave. Use it with single sign-on so new people can sign in.')">
            <div class="grid gap-5 p-4 sm:p-6">
                <template v-if="scimToken">
                    <Alert tone="warning">{{ t('Copy this token now; it isn’t shown again.') }}</Alert>
                    <CodeBlock :code="scimToken" class="whitespace-pre-wrap break-all" />
                </template>
                <div class="grid gap-2 rounded-control border border-line p-4 text-sm">
                    <p><span class="font-semibold text-ink">{{ t('SCIM base URL') }}</span><span class="mt-1 block break-all font-mono text-xs text-muted">{{ data.scim.baseUrl }}</span></p>
                    <p class="text-xs text-muted">{{ t('Authentication: HTTP header (bearer token). Unique identifier: userName, the person’s email address. Supported: creating, updating, deactivating and deleting users. Roles are set here, not in groups.') }}</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <Badge :tone="data.scim.on ? 'success' : 'neutral'">{{ data.scim.on ? t('On') : t('Off') }}</Badge>
                    <ApiForm action="/api/app/account/security/scim" method="PUT" :after="scim">
                        <input type="hidden" name="change" value="token">
                        <SubmitButton variant="secondary" size="sm">{{ data.scim.on ? t('Replace the token') : t('Turn on and create a token') }}</SubmitButton>
                    </ApiForm>
                    <ApiForm v-if="data.scim.on" action="/api/app/account/security/scim" method="PUT">
                        <input type="hidden" name="change" value="off">
                        <SubmitButton variant="quiet" size="sm">{{ t('Turn off') }}</SubmitButton>
                    </ApiForm>
                </div>
                <ApiForm action="/api/app/account/security/scim" method="PUT" class="!flex flex-wrap items-end !gap-3">
                    <input type="hidden" name="change" value="role">
                    <SelectField id="scim-default-role" v-model="scimRole" name="scim_default_role" :label="t('New people join as')" :options="roles" />
                    <SubmitButton variant="secondary">{{ t('Save') }}</SubmitButton>
                </ApiForm>
            </div>
        </SettingsSection>
    </div>
</template>
