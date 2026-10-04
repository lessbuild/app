<script setup lang="ts">
import type { Tone } from '~/types/ui';

/** Where a server is: queued, waiting for an IP address, provisioning, active or failed. */
const props = defineProps<{ status: string }>();
const { t } = useT();
const badge = computed<{ tone: Tone; label: string }>(() => {
    switch (props.status) {
        case 'active':
            return { tone: 'success', label: t('Active') };
        case 'failed':
            return { tone: 'danger', label: t('Failed') };
        case 'queued':
            return { tone: 'info', label: t('Queued') };
        case 'waiting_for_ip':
            return { tone: 'info', label: t('Waiting for an IP') };
        default:
            return { tone: 'info', label: t('Provisioning') };
    }
});
</script>

<template>
    <Badge :tone="badge.tone">{{ badge.label }}</Badge>
</template>
