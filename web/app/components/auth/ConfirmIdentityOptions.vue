<script setup lang="ts">
/** The ways to confirm it's you: password, passkey, or a provider you connected. Emits `confirmed` once you have. */
const props = defineProps<{ returnTo?: string }>();
const emit = defineEmits<{ confirmed: [] }>();
const { t } = useT();
type Me = { hasPassword: boolean; hasPasskeys: boolean; confirmProviders: Array<{ key: string; label: string; url: string }> };
const me = ref<Me | null>(null);
const status = ref<string | null>(null);

onMounted(() => {
    send<Me>('GET', '/api/app/auth/me')
        .then((value) => (me.value = value))
        .catch(() => (me.value = { hasPassword: true, hasPasskeys: false, confirmProviders: [] }));
});

async function passkey() {
    if (!passkeysSupported()) {
        status.value = t('This browser does not support passkeys.');
        return;
    }
    status.value = t('Waiting for your passkey…');
    try {
        await confirmWithPasskey();
        emit('confirmed');
    } catch {
        status.value = t('Passkey confirmation could not be completed. Please try again.');
    }
}

/** A round trip through the provider, then back to where the person was. */
async function provider(url: string) {
    const { redirect } = await send<{ redirect: string }>('POST', url, { redirect: props.returnTo });
    window.location.assign(redirect);
}
</script>

<template>
    <p v-if="me === null" class="text-sm text-muted" role="status">{{ t('Loading…') }}</p>
    <div v-else class="grid gap-5">
        <ApiForm v-if="me.hasPassword" action="/api/app/auth/user/confirm-password" :after="() => { emit('confirmed'); return null; }">
            <PasswordField name="password" :label="t('Password')" autocomplete="current-password" required autofocus />
            <SubmitButton class="w-full justify-center">{{ t('Confirm') }}</SubmitButton>
        </ApiForm>
        <div v-if="me.hasPasskeys" class="grid gap-2">
            <button type="button" :class="['ui-btn w-full justify-center', me.hasPassword ? 'ui-btn-secondary' : 'ui-btn-primary']" @click="passkey">{{ t('Confirm with a passkey') }}</button>
            <p role="status" aria-live="polite" :class="status ? 'text-center text-sm text-muted' : 'sr-only'">{{ status }}</p>
        </div>
        <button v-for="item in me.confirmProviders" :key="item.key" type="button" class="ui-btn ui-btn-secondary w-full justify-center" @click="provider(item.url)">
            {{ t('Confirm with :provider', { provider: item.label }) }}
        </button>
    </div>
</template>
