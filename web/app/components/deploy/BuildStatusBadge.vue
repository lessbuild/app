<script setup lang="ts">
import type { BuildStatus } from '~/types/deploy';
import type { Tone } from '~/types/ui';

/** Where a deploy is, as a badge with a dot (the Acme theme's status badge): live, failed, deploying, waiting… */
const props = defineProps<{ status: BuildStatus | string | null }>();
const { t } = useT();
const badge = computed<{ tone: Tone; label: string }>(() => {
    switch (props.status) {
        case 'succeeded':
            return { tone: 'success', label: t('Live') };
        case 'failed':
            return { tone: 'danger', label: t('Failed') };
        case 'rejected':
            return { tone: 'danger', label: t('Rejected') };
        case 'canceled':
            return { tone: 'neutral', label: t('Canceled') };
        case 'running':
        case 'deploying':
            return { tone: 'info', label: t('Deploying') };
        case 'awaiting_approval':
            return { tone: 'warning', label: t('Waiting for approval') };
        default:
            return { tone: 'neutral', label: t('Queued') };
    }
});
</script>

<template>
    <AcmeBadge :tone="acmeTone(badge.tone)" dot>{{ badge.label }}</AcmeBadge>
</template>
