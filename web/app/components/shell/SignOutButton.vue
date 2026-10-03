<script setup lang="ts">
/** Sign out through Laravel, then load the home page in full so nothing from the session stays in memory. */
const props = defineProps<{ action: string }>();
const { t } = useT();

async function signOut() {
    await send('POST', props.action, undefined, { signedOutRedirect: false }).catch(() => null);
    window.location.assign('/');
}
</script>

<template>
    <button type="button" class="topbar-nav-link w-full" @click="signOut">{{ t('Sign out') }}</button>
</template>
