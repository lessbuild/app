<script setup lang="ts">
/** Stops a status page's emails from the link in one of them, after the person confirms. */
const { t } = useT();
const route = useRoute();
const path = computed(() => `/status/subscriptions/${route.params.subscription}/unsubscribe/${route.params.token}`);
const { data } = await useApi<{ page: { name: string; slug: string } }>(path);
/** Back on the status page, saying it worked. */
const done = (result: Record<string, unknown>) => `/status/${data.value.page.slug}?notice=${encodeURIComponent(String(result.message ?? ''))}`;
useHead({ title: () => t('Unsubscribe'), meta: [{ name: 'robots', content: 'noindex' }] });
</script>

<template>
    <AuthFrame :heading="t('Stop :page emails?', { page: data.page.name })" :description="t('You won’t get incident or maintenance updates from this status page any more.')">
        <ApiForm :action="`/api/app${path}`" :after="done" class="flex flex-wrap gap-3">
            <SubmitButton>{{ t('Unsubscribe') }}</SubmitButton>
            <UiButton variant="quiet" :to="`/status/${data.page.slug}`">{{ t('Keep me subscribed') }}</UiButton>
        </ApiForm>
    </AuthFrame>
</template>
