<script setup lang="ts">
/** A website's files, as a page of their own (the website's Files tab shows the same). */
definePageMeta({ layout: 'app', service: 'infrastructure' });
const route = useRoute();
await navigateTo({ path: `/projects/${route.params.project}/infrastructure/websites/${route.params.website}`, query: { tab: 'files' } }, { replace: true });
</script>

<template>
    <div />
</template>
