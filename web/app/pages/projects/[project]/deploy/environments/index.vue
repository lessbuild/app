<script setup lang="ts">
import type { EnvironmentsPage } from '~/types/deploy';

/** How deploys run in each of the project's environments, at a glance. */
definePageMeta({ layout: 'app', service: 'deploy' });
const { t, tc } = useT();
const route = useRoute();
const { data } = await useApi<EnvironmentsPage>(() => `/projects/${route.params.project}/deploy/environments`);
const project = computed(() => data.value.overview.project);
const runtimes: Record<string, string> = { php: 'PHP', node: 'Node.js', python: 'Python', docker: 'Docker' };
const strategies = computed<Record<string, string>>(() => ({ rolling: t('Rolling'), blue_green: t('Blue-green'), canary: t('Canary') }));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Environments')" :description="t('How deploys run in each of the project’s environments: approvals, locks and windows, runtime, variables, workers and resources.')" />
        <DataTable :caption="t('Environments')">
            <template #head>
                <tr><th scope="col">{{ t('Environment') }}</th><th scope="col">{{ t('Deploys') }}</th><th scope="col">{{ t('Runtime') }}</th><th scope="col">{{ t('Settings') }}</th></tr>
            </template>
            <tr v-for="environment in data.environments" :key="environment.id">
                <td><NuxtLink :to="`/projects/${project.id}/deploy/environments/${environment.id}`" class="font-bold text-primary hover:underline">{{ environment.name }}</NuxtLink></td>
                <td>
                    <Badge v-if="environment.blocked" tone="warning">{{ environment.locked ? t('Locked') : t('Outside window') }}</Badge>
                    <Badge v-else tone="success">{{ environment.requiresApproval ? t('Need approval') : t('Open') }}</Badge>
                </td>
                <td class="text-muted">
                    {{ environment.runtime ? (runtimes[environment.runtime] ?? environment.runtime) : '—' }}<template v-if="environment.runtimeVersion"> {{ environment.runtimeVersion }}</template>
                    · {{ strategies[environment.strategy] ?? environment.strategy }}
                </td>
                <td class="text-muted">
                    {{ tc(':count variable|:count variables', environment.variables, { count: environment.variables }) }} ·
                    {{ tc(':count process|:count processes', environment.processes, { count: environment.processes }) }} ·
                    {{ tc(':count resource|:count resources', environment.resources, { count: environment.resources }) }}
                </td>
            </tr>
        </DataTable>
    </div>
</template>
