<script setup lang="ts">
/**
 * The sections of Monitoring's Alerts tab: the rules that open incidents, the destinations alerts go to, who's on call,
 * planned maintenance, and which alerts are noisy.
 */
const props = defineProps<{ projectId: string; current: 'rules' | 'destinations' | 'on-call' | 'maintenance' | 'noise' }>();
const { t } = useT();
const tabs = computed(() => {
    const base = `/projects/${props.projectId}/monitoring`;
    return [
        { key: 'rules', label: t('Rules'), to: `${base}/rules` },
        { key: 'destinations', label: t('Destinations'), to: `${base}/alerts` },
        { key: 'on-call', label: t('On-call'), to: `${base}/on-call` },
        { key: 'maintenance', label: t('Maintenance'), to: `${base}/maintenance` },
        { key: 'noise', label: t('Noise'), to: `${base}/alerts/noise` },
    ];
});
</script>

<template>
    <nav class="flex gap-6 overflow-x-auto overflow-y-hidden overscroll-x-contain border-b border-line [scrollbar-width:none]" :aria-label="t('Alerts sections')">
        <NuxtLink
            v-for="tab in tabs"
            :key="tab.key"
            :to="tab.to"
            :class="['shrink-0 whitespace-nowrap border-b-2 pb-3 text-sm font-medium', tab.key === current ? 'border-accent text-ink' : 'border-transparent text-muted hover:text-ink']"
            :aria-current="tab.key === current ? 'page' : undefined"
        >
            {{ tab.label }}
        </NuxtLink>
    </nav>
</template>
