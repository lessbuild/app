<script setup lang="ts">
/** Resend the verification link, or sign out to use another address. */
const { t } = useT();
const state = ref<'idle' | 'sending' | 'sent' | 'failed'>('idle');

async function resend() {
    state.value = 'sending';
    try {
        await send('POST', '/api/app/auth/email/verification-notification');
        state.value = 'sent';
    } catch {
        state.value = 'failed';
    }
}

async function signOut() {
    await send('POST', '/api/app/auth/logout', undefined, { signedOutRedirect: false }).catch(() => null);
    window.location.assign('/login');
}
</script>

<template>
    <div class="grid gap-4">
        <div v-if="state === 'sent'" class="ui-alert ui-alert--success ui-alert-success" role="status">{{ t('A new verification link is on its way.') }}</div>
        <div v-if="state === 'failed'" class="ui-alert ui-alert--danger ui-alert-danger" role="alert">{{ t('The link couldn’t be sent. Wait a minute and try again.') }}</div>
        <div class="flex flex-wrap items-center gap-3">
            <button type="button" class="ui-btn ui-btn-primary" :disabled="state === 'sending'" :aria-busy="state === 'sending' || undefined" @click="resend">{{ t('Resend link') }}</button>
            <button type="button" class="ui-btn ui-btn-quiet" @click="signOut">{{ t('Sign out') }}</button>
        </div>
    </div>
</template>
