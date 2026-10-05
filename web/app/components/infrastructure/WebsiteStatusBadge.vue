<script setup lang="ts">
import type { Tone } from '~/types/ui';

/** Where a website is: queued, setting up, live or failed. */
const props = defineProps<{ status: string }>();
const { t } = useT();
const badge = computed<{ tone: Tone; label: string }>(() => {
    switch (props.status) {
        case 'active':
            return { tone: 'success', label: t('Live') };
        case 'failed':
            return { tone: 'danger', label: t('Failed') };
        case 'queued':
            return { tone: 'info', label: t('Queued') };
        default:
            return { tone: 'info', label: t('Setting up') };
    }
});
</script>

<template>
    <AcmeBadge :tone="acmeTone(badge.tone)" dot>{{ badge.label }}</AcmeBadge>
</template>
