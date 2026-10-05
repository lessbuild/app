<script setup lang="ts">
/**
 * How the person signs in: password, two-factor authentication, passkeys, connected providers and SSH keys. Opening
 * it needs a recent confirmation of who they are.
 */
definePageMeta({ layout: 'app', area: 'settings' });
const { t, dateTime } = useT();
const route = useRoute();
type Security = {
    hasPassword: boolean;
    twoFactor: 'off' | 'pending' | 'on';
    pendingSecret: string | null;
    pendingQrCodeSvg: string | null;
    passkeys: Array<{ id: number; name: string; authenticator: string | null; createdAt: string | null; lastUsedAt: string | null }>;
};
type Provider = { key: string; label: string; configured: boolean; email: string | null; connectedAt: string | null; connected: boolean };
const { data } = await useApi<{ email: string; security: Security; providers: Provider[]; sshKeys: Array<{ id: number; name: string; fingerprint: string }> }>('/settings/security');
const security = computed(() => data.value.security);
const recoveryCodes = ref<string[]>([]);
const busy = ref(false);
const passkeyName = ref('');
const passkeyStatus = ref<string | null>(null);
const notice = computed(() => {
    if (route.query.connected === 'new') return { tone: 'success' as const, text: t('Account connected. You can now sign in with it.') };
    if (route.query.connected === 'already') return { tone: 'success' as const, text: t('That account was already connected.') };
    if (typeof route.query.social_error === 'string') return { tone: 'danger' as const, text: route.query.social_error };
    return null;
});

/** Show the recovery codes, right after they're made. */
async function showRecoveryCodes() {
    recoveryCodes.value = (await send<string[]>('GET', '/api/app/auth/user/two-factor-recovery-codes').catch(() => [])) ?? [];
}

/** Run a two-factor step (start, cancel, turn off, new codes), then say what happened. */
async function twoFactor(method: 'POST' | 'DELETE', path: string, message?: string, codes = false) {
    busy.value = true;
    try {
        await send(method, path);
        if (codes) {
            await showRecoveryCodes();
        }
        if (message) {
            flash(message);
        }
        await refreshPage();
    } finally {
        busy.value = false;
    }
}

/** Confirmed: two-factor is on; show the recovery codes once. */
function confirmed(): null {
    flash(t('Two-factor authentication is on.'));
    showRecoveryCodes();
    refreshPage();
    return null;
}

/** Add a passkey with the device's prompt. */
async function addPasskey() {
    if (!passkeysSupported()) {
        passkeyStatus.value = t('This browser does not support passkeys.');
        return;
    }
    passkeyStatus.value = t('Follow your device prompts to add a passkey…');
    try {
        await registerPasskey(passkeyName.value);
        passkeyName.value = '';
        passkeyStatus.value = null;
        await refreshPage();
    } catch {
        passkeyStatus.value = t('The passkey could not be added. Please try again.');
    }
}

/** Off to the provider to connect it; it comes back here. */
async function connect(provider: Provider) {
    const result = await send<{ redirect: string }>('POST', `/settings/security/social/${provider.key}`).catch(() => null);
    if (result) {
        window.location.assign(result.redirect);
    }
}
</script>

<template>
    <SettingsFrame :title="t('Security')" :description="t('How you sign in to :app.', { app: 'BuildPusher' })" >
        <AcmeAlert v-if="route.query.for === 'admin' && security.twoFactor !== 'on' && security.passkeys.length === 0" tone="warning" role="alert">{{ t('The admin panel needs a second factor. Add an authenticator app or a passkey below, then open it again.') }}</AcmeAlert>
        <AcmeAlert v-if="notice" :tone="notice.tone" :role="notice.tone === 'danger' ? 'alert' : 'status'">{{ notice.text }}</AcmeAlert>

        <section v-if="recoveryCodes.length > 0" class="ui-panel space-y-4 border-warning p-6" aria-labelledby="recovery-codes-heading">
            <div>
                <p class="ui-eyebrow">{{ t('Save these now') }}</p>
                <h2 id="recovery-codes-heading" class="mt-1 text-lg font-semibold text-ink">{{ t('Recovery codes') }}</h2>
                <p class="mt-2 text-sm leading-6 text-muted">{{ t('Each code signs you in once if you lose your authenticator. Store them somewhere private; they are not shown again.') }}</p>
            </div>
            <ul class="grid gap-2 font-mono text-sm sm:grid-cols-2" :aria-label="t('One-time recovery codes')">
                <li v-for="code in recoveryCodes" :key="code" class="rounded-control border border-line bg-surface-muted px-3 py-2 text-ink">{{ code }}</li>
            </ul>
        </section>

        <AcmeCard :padded="false" :title="security.hasPassword ? t('Password') : t('Set a password')" :description="t('Saving a new password signs out other browsers that were remembered.')">
            <ApiForm action="/api/app/auth/user/password" method="PUT" class="p-4 sm:p-6" :after="() => { flash(t('Password saved. Other browsers that were remembered have been signed out.')); refreshPage(); return null; }">
                <input type="hidden" name="email" :value="data.email" autocomplete="username">
                <PasswordField v-if="security.hasPassword" name="current_password" :label="t('Current password')" autocomplete="current-password" required />
                <PasswordField name="password" :label="t('New password')" autocomplete="new-password" required />
                <PasswordField name="password_confirmation" :label="t('Confirm new password')" autocomplete="new-password" required />
                <div><SubmitButton>{{ security.hasPassword ? t('Update password') : t('Set password') }}</SubmitButton></div>
            </ApiForm>
        </AcmeCard>

        <AcmeCard :padded="false" :title="t('Two-factor authentication')" :description="t('Ask for a code from an authenticator app whenever you sign in with a password.')">
            <div class="grid gap-5 px-5 pb-5 sm:px-6 sm:pb-6">
                <div><AcmeBadge :tone="acmeTone(security.twoFactor === 'on' ? 'success' : 'neutral')">{{ security.twoFactor === 'on' ? t('On') : security.twoFactor === 'pending' ? t('Finish setup') : t('Off') }}</AcmeBadge></div>
                <div v-if="security.twoFactor === 'on'" class="flex flex-wrap gap-3">
                    <AcmeBtn :disabled="busy" @click="twoFactor('POST', '/api/app/auth/user/two-factor-recovery-codes', t('New recovery codes were created. The old ones no longer work.'), true)">{{ t('Create new recovery codes') }}</AcmeBtn>
                    <AcmeBtn variant="danger" :disabled="busy" @click="twoFactor('DELETE', '/api/app/auth/user/two-factor-authentication', t('Two-factor authentication is off.'))">{{ t('Turn off two-factor authentication') }}</AcmeBtn>
                </div>
                <template v-else-if="security.twoFactor === 'pending'">
                    <p class="text-sm leading-6 text-muted">{{ t('Scan this code with your authenticator app, or enter the setup key by hand, then type the code it shows.') }}</p>
                    <div class="w-fit rounded-control border border-line bg-white p-3" data-two-factor-qr-code v-html="security.pendingQrCodeSvg" />
                    <UiField id="setup-key" :label="t('Setup key')">
                        <input id="setup-key" class="ui-input font-mono" :value="security.pendingSecret" readonly>
                    </UiField>
                    <ApiForm action="/api/app/auth/user/confirmed-two-factor-authentication" class="sm:max-w-sm" :after="confirmed">
                        <InputField name="code" :label="t('Code from your app')" inputmode="numeric" autocomplete="one-time-code" required />
                        <div><SubmitButton>{{ t('Confirm and turn on') }}</SubmitButton></div>
                    </ApiForm>
                    <div><AcmeBtn variant="ghost" :disabled="busy" @click="twoFactor('DELETE', '/api/app/auth/user/two-factor-authentication')">{{ t('Cancel setup') }}</AcmeBtn></div>
                </template>
                <div v-else><AcmeBtn variant="primary" :disabled="busy" @click="twoFactor('POST', '/api/app/auth/user/two-factor-authentication')">{{ t('Set up an authenticator app') }}</AcmeBtn></div>
            </div>
        </AcmeCard>

        <AcmeCard :padded="false" :title="t('Passkeys')" :description="t('Sign in with your device screen lock, a biometric or a security key instead of a password.')">
            <div class="grid gap-5 px-5 pb-5 sm:px-6 sm:pb-6">
                <p v-if="security.passkeys.length === 0" class="text-sm text-muted">{{ t('No passkeys yet.') }}</p>
                <ul v-else class="grid gap-3" :aria-label="t('Your passkeys')">
                    <li v-for="passkey in security.passkeys" :key="passkey.id" class="flex flex-wrap items-center justify-between gap-4 rounded-panel border border-line p-4">
                        <div class="min-w-0">
                            <p class="break-words text-sm font-bold text-ink">{{ passkey.name }}</p>
                            <p class="mt-1 text-xs leading-5 text-muted">
                                {{ passkey.authenticator ?? t('Unknown authenticator') }}
                                · {{ t('Added :time', { time: passkey.createdAt ? dateTime(passkey.createdAt) : t('recently') }) }}
                                · {{ t('Last used :time', { time: passkey.lastUsedAt ? dateTime(passkey.lastUsedAt) : t('never') }) }}
                            </p>
                        </div>
                        <DeleteDialog :id="`remove-passkey-${passkey.id}`" :title="t('Remove passkey :name', { name: passkey.name })" :action="`/api/app/auth/user/passkeys/${passkey.id}`" :submit-label="t('Remove')">
                            <template #trigger="{ open }"><AcmeBtn variant="ghost" @click="open">{{ t('Remove') }}</AcmeBtn></template>
                        </DeleteDialog>
                    </li>
                </ul>
                <form class="grid gap-4 rounded-panel border border-line bg-surface-muted p-4 sm:max-w-xl" @submit.prevent="addPasskey">
                    <UiField id="passkey-name" :label="t('Passkey name')" :description="t('A name you will recognise, such as “Work laptop”.')">
                        <input id="passkey-name" v-model="passkeyName" class="ui-input" maxlength="255" autocomplete="off" required aria-describedby="passkey-name-help">
                    </UiField>
                    <div><AcmeBtn type="submit" variant="primary">{{ t('Add a passkey') }}</AcmeBtn></div>
                </form>
                <p role="status" aria-live="polite" class="min-h-5 text-sm text-muted">{{ passkeyStatus }}</p>
            </div>
        </AcmeCard>

        <AcmeCard :padded="false" :title="t('Connected accounts')" :description="t('Sign in with GitHub, GitLab or Bitbucket. An account is only connected from here, never matched by email alone.')">
            <ul class="grid gap-3 px-5 pb-5 sm:px-6 sm:pb-6" :aria-label="t('Sign-in providers')">
                <li v-for="provider in data.providers" :key="provider.key" class="flex flex-wrap items-center justify-between gap-4 rounded-panel border border-line p-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-sm font-bold text-ink">{{ provider.label }}</p>
                            <AcmeBadge :tone="acmeTone(provider.connected ? 'success' : 'neutral')">{{ provider.connected ? t('Connected') : t('Not connected') }}</AcmeBadge>
                        </div>
                        <p v-if="provider.connected" class="mt-1 break-all text-xs leading-5 text-muted">
                            {{ provider.email ?? t('No email shared') }} · {{ t('Connected :time', { time: provider.connectedAt ? dateTime(provider.connectedAt) : t('recently') }) }}
                        </p>
                        <p v-else-if="!provider.configured" class="mt-1 text-xs leading-5 text-muted">{{ t('Not available yet.') }}</p>
                    </div>
                    <DeleteDialog v-if="provider.connected" :id="`disconnect-${provider.key}`" :title="t('Disconnect :provider', { provider: provider.label })" :action="`/api/app/settings/security/social/${provider.key}`" :submit-label="t('Disconnect')">
                        <template #trigger="{ open }"><AcmeBtn variant="ghost" @click="open">{{ t('Disconnect') }}</AcmeBtn></template>
                    </DeleteDialog>
                    <AcmeBtn v-else-if="provider.configured" @click="connect(provider)">{{ t('Connect :provider', { provider: provider.label }) }}</AcmeBtn>
                </li>
            </ul>
        </AcmeCard>

        <AcmeCard id="ssh-keys" :padded="false" :title="t('SSH keys')" :description="t('Your public keys. When someone gives you SSH access to a server (Security → Servers), these are the keys installed there; changes here reach those servers within a minute.')">
            <div class="grid gap-4 px-5 pb-5 sm:px-6 sm:pb-6">
                <p v-if="data.sshKeys.length === 0" class="text-sm text-muted">{{ t('No SSH keys yet.') }}</p>
                <div v-for="key in data.sshKeys" :key="key.id" class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <span><span class="font-bold text-ink">{{ key.name }}</span> <span class="font-mono text-xs text-muted">{{ key.fingerprint }}</span></span>
                    <DeleteDialog :id="`remove-key-${key.id}`" :title="t('Remove :name?', { name: key.name })" :action="`/api/app/settings/ssh-keys/${key.id}`" :submit-label="t('Remove')">
                        <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Remove') }}</AcmeBtn></template>
                    </DeleteDialog>
                </div>
                <ApiForm action="/api/app/settings/ssh-keys" class="grid gap-3">
                    <InputField name="name" :label="t('Name')" maxlength="100" placeholder="MacBook" required />
                    <TextareaField name="public_key" :label="t('Public key')" rows="3" placeholder="ssh-ed25519 AAAA…" :description="t('The contents of your .pub file, such as ~/.ssh/id_ed25519.pub. Never paste a private key.')" required />
                    <div><SubmitButton variant="secondary">{{ t('Add key') }}</SubmitButton></div>
                </ApiForm>
            </div>
        </AcmeCard>
    </SettingsFrame>
</template>
