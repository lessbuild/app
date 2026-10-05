<script setup lang="ts">
import type { Tone } from '~/types/ui';

/** How a backup, restore or check went: queued, running, done or failed. */
const props = defineProps<{ status: string }>();
const { t } = useT();
const badge = computed<{ tone: Tone; label: string }>(() => {
    switch (props.status) {
        case 'succeeded':
            return { tone: 'success', label: t('Done') };
        case 'failed':
            return { tone: 'danger', label: t('Failed') };
        case 'running':
            return { tone: 'info', label: t('Running') };
        default:
            return { tone: 'neutral', label: t('Queued') };
    }
});
</script>

<template>
    <AcmeBadge :tone="acmeTone(badge.tone)" dot>{{ badge.label }}</AcmeBadge>
</template>
