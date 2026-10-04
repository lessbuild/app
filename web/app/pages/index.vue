<script setup lang="ts">
/**
 * The root address: on a status page's custom domain it's that status page; on the app's own host it's the projects
 * dashboard (the public site lives elsewhere).
 */
const config = useRuntimeConfig();
const host = useRequestURL().hostname.toLowerCase();
const read = useApiReader();
const { data: slug } = await useAsyncData(`status-domain:${host}`, async () => {
    if (host === config.public.appHost || !/^[a-z0-9.-]+$/.test(host)) {
        return null;
    }
    return read<{ slug: string }>(`/status-domains/${host}`).then((found) => found.slug, () => null);
});
if (!slug.value) {
    await navigateTo('/dashboard', { replace: true });
}
</script>

<template>
    <StatusPageView v-if="slug" :slug="slug" />
</template>
