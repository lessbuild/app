<script setup lang="ts">
/** A site's report for someone given view-only access, from their own link. */
const route = useRoute();
</script>

<template>
    <PublicReport :api="`/analytics/view/${route.params.token}`" />
</template>
