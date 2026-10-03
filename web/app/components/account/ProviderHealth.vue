<script setup lang="ts">
/** A provider's connection status as a badge. */
defineProps<{ status: string }>();
const { t } = useT();
</script>

<template>
    <Badge :tone="status === 'healthy' ? 'success' : status === 'failed' ? 'danger' : 'neutral'">
        {{ status === 'healthy' ? t('Connected') : status === 'failed' ? t('Can’t connect') : t('Not checked') }}
    </Badge>
</template>
