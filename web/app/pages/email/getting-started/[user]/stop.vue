<script setup lang="ts">
/**
 * The link in getting-started emails: ask, then stop them. The address is signed, so posting to it (as a mail
 * client's one-click unsubscribe does) is all it takes; no sign-in needed.
 */
const { t } = useT();
const done = ref<string | null>(null);
const failed = ref(false);
const busy = ref(false);

async function stop() {
    busy.value = true;
    const response = await fetch(window.location.pathname + window.location.search, { method: 'POST', headers: { Accept: 'application/json' } }).catch(() => null);
    if (response?.ok) {
        done.value = ((await response.json()) as { message: string }).message;
    } else {
        failed.value = true;
    }
    busy.value = false;
}
</script>

<template>
    <AuthFrame :eyebrow="t('Getting-started emails')" :heading="t('Stop getting-started emails?')">
        <div v-if="done" class="ui-alert ui-alert--success ui-alert-success" role="status">{{ done }}</div>
        <div v-else-if="failed" class="ui-alert ui-alert--danger ui-alert-danger" role="alert">{{ t('This link has expired. Change your emails in your notification settings instead.') }}</div>
        <button v-else type="button" class="ui-btn ui-btn-primary w-full justify-center" :disabled="busy" :aria-busy="busy || undefined" @click="stop">{{ busy ? t('Working…') : t('Stop these emails') }}</button>
    </AuthFrame>
</template>
