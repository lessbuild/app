<script setup lang="ts">
import type { Me } from '~/types/auth';

/** Confirm it's you before a sensitive change (when the app couldn't ask in a dialog), then go back to it. */
const { t } = useT();
const route = useRoute();
const { data: me } = await useApi<Me>('/auth/me');
await setLocale(me.value.locale);
const redirect = safeRedirect(route.query.redirect);

/** Go back to the change, loading it in full so it sees the confirmation. */
function back() {
    window.location.assign(redirect);
}
</script>

<template>
    <AuthFrame :eyebrow="t('Account security')" :heading="t('Confirm it’s you')" :description="t('This is a sensitive action. Confirm your identity to continue; you won’t be asked again for a while.')">
        <ConfirmIdentityOptions :return-to="redirect" @confirmed="back" />
    </AuthFrame>
</template>
