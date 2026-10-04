<script setup lang="ts">
import type { MonitorForm } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/** Edit a monitor's settings (its type and environment stay as they are). */
definePageMeta({ layout: 'app', service: 'monitoring' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<MonitorForm & { overview: ProjectOverview }>(() => `/projects/${route.params.project}/monitoring/monitors/${route.params.monitor}/edit`);
const environments = computed(() => data.value.overview.environments.map((environment) => ({ value: environment.id, label: environment.name })));
</script>

<template>
    <div v-if="data.monitor" class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Edit :monitor', { monitor: data.monitor.name })" :description="t('Only monitor endpoints, domains and jobs you own or are allowed to check.')" />
        <section class="ui-card p-4 sm:p-6">
            <MonitorForm :form="data" :project-id="data.overview.project.id" :environments="environments" :type="data.monitor.type" />
        </section>
    </div>
</template>
