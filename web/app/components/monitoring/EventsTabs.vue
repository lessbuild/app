<script setup lang="ts">
/** The tabs between Monitoring's event stream and its service map (the Acme theme's events tabs). */
defineProps<{ projectId: string; current: 'stream' | 'map' }>();
const { t } = useT();
</script>

<template>
    <nav class="flex gap-6 overflow-x-auto overflow-y-hidden overscroll-x-contain border-b border-line [scrollbar-width:none]" :aria-label="t('Events view')">
        <NuxtLink
            v-for="tab in [{ key: 'stream', label: t('Stream'), to: `/projects/${projectId}/monitoring/events` }, { key: 'map', label: t('Service map'), to: `/projects/${projectId}/monitoring/dependencies` }]"
            :key="tab.key"
            :to="tab.to"
            :class="['shrink-0 whitespace-nowrap border-b-2 pb-3 text-sm font-medium', tab.key === current ? 'border-accent text-ink' : 'border-transparent text-muted hover:text-ink']"
            :aria-current="tab.key === current ? 'page' : undefined"
        >
            {{ tab.label }}
        </NuxtLink>
    </nav>
</template>
