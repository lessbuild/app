<script setup lang="ts">
/** What a dialog shows while it loads what it needs, or when that failed. */
defineProps<{ failed: boolean }>();
const { t } = useT();
</script>

<template>
    <Alert v-if="failed" tone="danger" role="alert">{{ t('Something went wrong. Try again.') }}</Alert>
    <p v-else class="text-sm text-muted" role="status">{{ t('Loading…') }}</p>
</template>
