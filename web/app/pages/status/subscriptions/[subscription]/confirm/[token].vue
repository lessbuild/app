<script setup lang="ts">
/**
 * Confirms an email subscription from its confirmation email's link. It confirms once the page has loaded in a
 * browser, so mail scanners that only fetch the link don't confirm it for the person; then it goes to the status page.
 */
const { t } = useT();
const route = useRoute();
const failed = ref(false);
onMounted(async () => {
    const result = await send<{ redirect: string; message: string }>('POST', `/status/subscriptions/${route.params.subscription}/confirm/${route.params.token}`).catch(() => null);
    if (result === null) {
        failed.value = true;
        return;
    }
    await navigateTo({ path: local(result.redirect), query: { notice: result.message } }, { replace: true });
});
useHead({ title: () => t('Confirm status updates'), meta: [{ name: 'robots', content: 'noindex' }] });
</script>

<template>
    <AuthFrame :heading="t('Confirm status updates')" :description="failed ? t('This link has expired or was already used.') : t('Confirming…')">
        <p v-if="failed" class="text-sm text-muted">{{ t('If you still want updates, subscribe again from the status page.') }}</p>
    </AuthFrame>
</template>
