<script setup lang="ts">
import type { WebsitePage } from '~/types/infrastructure';

/** A website's setup log and its health check. */
const props = defineProps<{ page: WebsitePage }>();
const { t } = useT();
const health = computed(() => props.page.health);
const website = computed(() => props.page.website);
const tone = computed(() => ({ Up: 'success', Down: 'danger', Paused: 'neutral' } as const)[health.value.monitor?.state as 'Up' | 'Down' | 'Paused'] ?? 'warning');
</script>

<template>
    <div class="space-y-10">
        <SettingsSection v-if="page.log" :title="t('Setup log')" :description="t('The last run of the setup script.')">
            <CodeBlock class="m-4 max-h-96 overflow-auto whitespace-pre-wrap sm:m-6" :code="page.log" />
        </SettingsSection>
        <SettingsSection :title="t('Health')" :description="t('Health checks run in Monitoring, so failures open incidents and use its alert routing.')">
            <div class="p-4 text-sm sm:p-6">
                <p v-if="health.monitor" class="flex flex-wrap items-center gap-2">
                    <Badge :tone="tone">{{ health.monitor.label }}</Badge>
                    <NuxtLink :to="`/projects/${health.monitor.projectId}/monitoring/monitors/${health.monitor.id}`" class="font-bold text-primary hover:underline">{{ t('Open the monitor') }}</NuxtLink>
                    <span class="text-muted">https://{{ website.url }}{{ website.healthCheckPath }}</span>
                </p>
                <p v-else-if="!website.healthCheckEnabled" class="text-muted">{{ t('Health checks are off.') }}</p>
                <p v-else-if="!health.environment" class="text-muted">{{ t('Link the website to an environment to check its health.') }}</p>
                <p v-else class="text-muted">{{ t('Turn on Monitoring for :project to check this website’s health.', { project: health.environment.project }) }}</p>
            </div>
        </SettingsSection>
    </div>
</template>
