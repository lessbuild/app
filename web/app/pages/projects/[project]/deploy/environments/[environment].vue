<script setup lang="ts">
import type { EnvironmentPage } from '~/types/deploy';

/**
 * One environment's deploy settings (the Acme theme's environment page), a tab per area: controls and freezes, how
 * deploys run, variables, workers, resources, automation, recipes and notifications, with an "On this page" list
 * beside the longer ones. Changes apply to the next deploy.
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
const sections = computed<Record<string, Array<[string, string]>>>(() => ({
    controls: [['controls', t('Deployment controls')], ['freezes', t('Freezes')], ['regions', t('Regions')]],
    settings: [['settings', t('How deploys run')], ['build-server', t('Build server')]],
}));
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="data.environment.name" :description="t('Deploy settings for this environment. Changes apply to the next deploy.')">
            <template #actions>
                <AcmeBadge v-if="data.environment.protected" tone="violet" dot>{{ t('Protected environment') }}</AcmeBadge>
                <AcmeBadge v-if="data.blockReason" tone="red" dot>{{ t('Deploys locked') }}</AcmeBadge>
            </template>
        </ProjectHeader>
        <div class="space-y-6">
            <AcmeAlert v-if="data.blockReason" tone="warning">{{ data.blockReason }} {{ t('Pushes wait and deploy once allowed.') }}</AcmeAlert>

            <PageTabs :tabs="tabs" :current="tab" :label="t('Environment sections')" />

            <div :class="sections[tab] && 'grid gap-8 lg:grid-cols-[12rem_1fr]'">
                <nav v-if="sections[tab]" class="hidden lg:block" :aria-label="t('On this page')">
                    <ul class="sticky top-4 space-y-1 border-l border-line text-sm">
                        <li v-for="[anchor, title] in sections[tab]" :key="anchor"><a :href="`#${anchor}`" class="-ml-px block border-l border-transparent py-1 pl-3 text-muted hover:border-accent hover:text-ink">{{ title }}</a></li>
                    </ul>
                </nav>
                <div class="min-w-0">
                    <EnvironmentControls v-if="tab === 'controls'" :page="data" :base="base" />
                    <EnvironmentSettings v-else-if="tab === 'settings'" :page="data" :base="base" />
                    <EnvironmentVariables v-else-if="tab === 'variables'" :page="data" :base="base" />
                    <EnvironmentProcesses v-else-if="tab === 'processes'" :page="data" :base="base" />
                    <EnvironmentResources v-else-if="tab === 'resources'" :page="data" :base="base" />
                    <EnvironmentAutomation v-else-if="tab === 'automation'" :page="data" :base="base" />
                    <EnvironmentRecipes v-else-if="tab === 'recipes'" :page="data" :base="base" />
                    <EnvironmentNotifications v-else :page="data" :base="base" />
                </div>
            </div>
        </div>
    </div>
</template>
