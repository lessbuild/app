<script setup lang="ts">
/** A site's report shared by link: no account needed; a protected one asks for its password first. */
const route = useRoute();
const token = computed(() => String(route.params.token));
</script>

<template>
    <PublicReport :api="`/share/analytics/${token}`" :unlock="`/api/app/share/analytics/${token}/unlock`" />
</template>
