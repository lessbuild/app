<script setup lang="ts">
import type { Tone } from '~/types/ui';

/** How an objective is doing as a badge: healthy, warning (under a quarter of the budget left), exhausted, no data or off. */
const props = defineProps<{ status: string; enabled: boolean }>();
const { t } = useT();
const badge = computed<{ tone: Tone; label: string }>(() => {
    if (!props.enabled) {
        return { tone: 'neutral', label: t('Off') };
    }
    return ({
        healthy: { tone: 'success', label: t('Healthy') },
        warning: { tone: 'warning', label: t('Budget running low') },
        exhausted: { tone: 'danger', label: t('Budget exhausted') },
    } as Record<string, { tone: Tone; label: string }>)[props.status] ?? { tone: 'neutral', label: t('No data') };
});
</script>

<template>
    <Badge :tone="badge.tone">{{ badge.label }}</Badge>
</template>
