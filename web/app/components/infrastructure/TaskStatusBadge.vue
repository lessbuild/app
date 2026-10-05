<script setup lang="ts">
import type { Tone } from '~/types/ui';

/** Where a cron job, process, firewall rule or service stands on its server, with the server's error when it failed. */
const props = defineProps<{ status: string; error?: string | null }>();
const { t } = useT();
const badge = computed<{ tone: Tone; label: string }>(() => {
    switch (props.status) {
        case 'active':
            return { tone: 'success', label: t('In place') };
        case 'pending':
            return { tone: 'info', label: t('Setting up') };
        case 'removing':
            return { tone: 'warning', label: t('Removing') };
        case 'failed':
            return { tone: 'danger', label: t('Failed') };
        default:
            return { tone: 'neutral', label: props.status };
    }
});
</script>

<template>
    <span class="inline-grid gap-1">
        <AcmeBadge :tone="acmeTone(badge.tone)" :title="error ?? undefined">{{ badge.label }}</AcmeBadge>
        <span v-if="status === 'failed' && error" class="text-xs text-danger">{{ error }}</span>
    </span>
</template>
