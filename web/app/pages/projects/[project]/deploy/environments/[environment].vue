<script setup lang="ts">
import type { EnvironmentPage } from '~/types/deploy';

/**
 * One environment's deploy settings, a tab per area: controls and freezes, how deploys run, variables, workers,
 * resources, automation, recipes and notifications. Changes apply to the next deploy.
 */
definePageMeta({ layout: 'app', service: 'deploy' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<EnvironmentPage>(() => `/projects/${route.params.project}/deploy/environments/${route.params.environment}`);
const base = computed(() => `/api/app/projects/${data.value.overview.project.id}/deploy/environments/${data.value.environment.id}`);
const tabs = computed<Record<string, string>>(() => ({
    controls: t('Controls'),
    settings: t('Settings'),
    variables: t('Variables'),
    processes: t('Workers'),
    resources: t('Resources'),
    automation: t('Automation'),
    recipes: t('Recipes'),
    notifications: t('Notifications'),
}));
const tab = computed(() => (typeof route.query.tab === 'string' && route.query.tab in tabs.value ? route.query.tab : 'controls'));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="data.environment.name" :description="t('Deploy settings for this environment. Changes apply to the next deploy.')">
            <template #actions>
                <Badge v-if="data.environment.protected" tone="accent"><Icon name="shield" class="h-3.5 w-3.5" />{{ t('Protected environment') }}</Badge>
            </template>
        </ProjectHeader>
        <Alert v-if="data.blockReason" tone="warning">{{ data.blockReason }} {{ t('Pushes wait and deploy once allowed.') }}</Alert>

        <PageTabs :tabs="tabs" :current="tab" :label="t('Environment sections')" />

        <EnvironmentControls v-if="tab === 'controls'" :page="data" :base="base" />
        <EnvironmentSettings v-else-if="tab === 'settings'" :page="data" :base="base" />
        <EnvironmentVariables v-else-if="tab === 'variables'" :page="data" :base="base" />
        <EnvironmentProcesses v-else-if="tab === 'processes'" :page="data" :base="base" />
        <EnvironmentResources v-else-if="tab === 'resources'" :page="data" :base="base" />
        <EnvironmentAutomation v-else-if="tab === 'automation'" :page="data" :base="base" />
        <EnvironmentRecipes v-else-if="tab === 'recipes'" :page="data" :base="base" />
        <EnvironmentNotifications v-else :page="data" :base="base" />
    </div>
</template>
