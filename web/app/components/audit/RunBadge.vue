<script setup lang="ts">
import type { RunSummary } from '~/types/audit';

/** A run's state, or its score once done, with words as well as colour. */
const props = defineProps<{ run: RunSummary }>();
const { t } = useT();
const badge = computed(() => {
    if (props.run.status === 'done' && props.run.score !== null) {
        return { tone: scoreTone(props.run.score), label: t('Score :score', { score: props.run.score }) };
    }
    return {
        queued: { tone: 'neutral' as const, label: t('Waiting to start') },
        running: { tone: 'info' as const, label: t('Running') },
        done: { tone: 'success' as const, label: t('Done') },
        failed: { tone: 'danger' as const, label: t('Failed') },
    }[props.run.status];
});
</script>

<template>
    <AcmeBadge :tone="acmeTone(badge.tone)" dot>{{ badge.label }}</AcmeBadge>
</template>
