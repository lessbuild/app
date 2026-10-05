<script setup lang="ts">
import type { WebsitePage } from '~/types/infrastructure';

/** A website's overview (the Acme theme's website overview): its status at a glance, its health check and setup log. */
const props = defineProps<{ page: WebsitePage }>();
const { t } = useT();
const health = computed(() => props.page.health);
const website = computed(() => props.page.website);
const tone = computed(() => ({ Up: 'green', Down: 'red', Paused: 'gray' } as const)[health.value.monitor?.state as 'Up' | 'Down' | 'Paused'] ?? 'amber');
const facts = computed(() => [
    { label: t('Server'), value: website.value.server ?? '—' },
    { label: t('Environment'), value: props.page.environment ?? t('Not linked') },
    { label: t('Releases kept'), value: String(website.value.releaseRetention) },
    { label: t('Database'), value: t(':name on localhost', { name: website.value.database }) },
]);
</script>

<template>
    <div class="space-y-6">
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div v-for="fact in facts" :key="fact.label" class="rounded-2xl border border-line bg-surface p-4 shadow-card">
                <p class="text-xs text-muted">{{ fact.label }}</p>
                <p class="mt-2 truncate font-medium text-ink" :title="fact.value">{{ fact.value }}</p>
            </div>
        </div>
        <AcmeCard :title="t('Health')" :description="t('Health checks run in Monitoring, so failures open incidents and use its alert routing.')">
            <div v-if="health.monitor" class="flex flex-wrap items-center gap-3 text-sm">
                <AcmeBadge :tone="tone" dot>{{ health.monitor.label }}</AcmeBadge>
                <span class="text-muted">GET https://{{ website.url }}{{ website.healthCheckPath }}</span>
                <AcmeBtn size="sm" class="ml-auto" :to="`/projects/${health.monitor.projectId}/monitoring/monitors/${health.monitor.id}`">{{ t('Open the monitor') }}</AcmeBtn>
            </div>
            <p v-else-if="!website.healthCheckEnabled" class="text-sm text-muted">{{ t('Health checks are off. Turn them on in Settings.') }}</p>
            <p v-else-if="!health.environment" class="text-sm text-muted">{{ t('Link the website to an environment to check its health.') }}</p>
            <p v-else class="text-sm text-muted">{{ t('Turn on Monitoring for :project to check this website’s health.', { project: health.environment.project }) }}</p>
        </AcmeCard>
        <AcmeCard v-if="page.log" :title="t('Setup log')" :description="t('The last run of the setup script.')" :padded="false">
            <pre class="max-h-72 overflow-auto rounded-b-2xl bg-zinc-950 px-5 py-4 font-mono text-xs leading-6 whitespace-pre-wrap text-zinc-300">{{ page.log }}</pre>
        </AcmeCard>
    </div>
</template>
