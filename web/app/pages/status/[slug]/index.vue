<script setup lang="ts">
/** A published status page at /status/{slug}. */
const route = useRoute();
</script>

<template>
    <StatusPageView :slug="String(route.params.slug)" />
</template>
