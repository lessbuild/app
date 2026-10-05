<script setup lang="ts">
/** Stops BuildPusher status emails from the link in one of them, after the person confirms. */
const { t } = useT();
const route = useRoute();
const path = computed(() => `/platform-status/subscribers/${route.params.subscriber}/unsubscribe/${route.params.token}`);
/**
 * Back on the status page, saying it worked.
 *
 * @param result The API's answer.
 */
const done = (result: Record<string, unknown>) => `/status?notice=${encodeURIComponent(String(result.message ?? ''))}`;
useHead({ title: () => t('Unsubscribe'), meta: [{ name: 'robots', content: 'noindex' }] });
</script>

<template>
    <AuthFrame :heading="t('Stop :app status emails?', { app: 'BuildPusher' })" :description="t('You won’t get an email when part of :app stops working or is fixed.', { app: 'BuildPusher' })">
        <ApiForm :action="`/api/app${path}`" :after="done" class="flex flex-wrap gap-3">
            <SubmitButton>{{ t('Unsubscribe') }}</SubmitButton>
            <UiButton variant="quiet" to="/status">{{ t('Keep me subscribed') }}</UiButton>
        </ApiForm>
    </AuthFrame>
</template>
