<script setup lang="ts">
/** Stops posting a status page's updates to a Slack channel or webhook, from the link in one of its messages. */
const { t } = useT();
const route = useRoute();
const path = computed(() => `/status/webhooks/${route.params.subscription}/unsubscribe/${route.params.token}`);
const { data } = await useApi<{ page: { name: string; slug: string }; type: 'slack' | 'webhook' }>(path);
/** Back on the status page, saying it worked. */
const done = (result: Record<string, unknown>) => `/status/${data.value.page.slug}?notice=${encodeURIComponent(String(result.message ?? ''))}`;
useHead({ title: () => t('Unsubscribe'), meta: [{ name: 'robots', content: 'noindex' }] });
</script>

<template>
    <AuthFrame
        :heading="t('Stop :page updates?', { page: data.page.name })"
        :description="data.type === 'slack' ? t('Updates from this status page won’t be posted to that Slack channel any more.') : t('Updates from this status page won’t be sent to that webhook any more.')"
    >
        <ApiForm :action="`/api/app${path}`" :after="done" class="flex flex-wrap gap-3">
            <SubmitButton>{{ t('Unsubscribe') }}</SubmitButton>
            <UiButton variant="quiet" :to="`/status/${data.page.slug}`">{{ t('Keep it subscribed') }}</UiButton>
        </ApiForm>
    </AuthFrame>
</template>
