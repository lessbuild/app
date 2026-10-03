<script setup lang="ts">
/** Sign in with a password (then the two-factor challenge if it's on) or a passkey, and go back where they were going. */
const props = defineProps<{ redirect: string }>();
const { t } = useT();
const passkeyStatus = ref<string | null>(null);
const working = ref(false);

async function passkey() {
    if (!passkeysSupported()) {
        passkeyStatus.value = t('This browser does not support passkeys.');
        return;
    }
    working.value = true;
    passkeyStatus.value = t('Waiting for your passkey…');
    try {
        const remember = (document.getElementById('remember') as HTMLInputElement | null)?.checked ?? false;
        await signInWithPasskey(remember);
        window.location.assign(props.redirect);
    } catch {
        passkeyStatus.value = t('Passkey sign-in could not be completed. Please try again.');
        working.value = false;
    }
}
</script>

<template>
    <ApiForm action="/api/app/auth/login" :after="(data) => (data.two_factor === true ? `/two-factor-challenge?redirect=${encodeURIComponent(redirect)}` : redirect)">
        <InputField name="email" :label="t('Email address')" type="email" autocomplete="username webauthn" required autofocus />
        <PasswordField name="password" :label="t('Password')" autocomplete="current-password" required />
        <div class="flex flex-wrap items-center justify-between gap-3">
            <CheckboxField id="remember" name="remember" :label="t('Remember me')" />
            <NuxtLink to="/forgot-password" class="rounded-sm text-sm font-bold text-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                {{ t('Forgot password?') }}
            </NuxtLink>
        </div>
        <SubmitButton class="w-full justify-center">{{ t('Sign in') }}</SubmitButton>
        <div class="grid gap-2">
            <button type="button" class="ui-btn ui-btn-secondary w-full justify-center" :disabled="working" :aria-busy="working || undefined" @click="passkey">
                {{ t('Sign in with a passkey') }}
            </button>
            <p role="status" aria-live="polite" :class="passkeyStatus ? 'text-center text-sm text-muted' : 'sr-only'">{{ passkeyStatus }}</p>
            <NuxtLink to="/login/sso" class="ui-btn ui-btn-quiet w-full justify-center">{{ t('Sign in with single sign-on') }}</NuxtLink>
        </div>
    </ApiForm>
</template>
