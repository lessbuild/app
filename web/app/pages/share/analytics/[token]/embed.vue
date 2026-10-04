<script setup lang="ts">
/** A shared report embedded in another site's page (framing it is allowed for this page only). */
const route = useRoute();
</script>

<template>
    <PublicReport :api="`/share/analytics/${route.params.token}?embed=1`" />
</template>
