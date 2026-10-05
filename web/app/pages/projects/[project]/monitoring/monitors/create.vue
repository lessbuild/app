<script setup lang="ts">
import type { MonitorForm } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/** Add a monitor (the Acme theme's add-monitor page): pick what kind, then fill in what it checks. `?check_type=` picks one first. */
definePageMeta({ layout: 'app', service: 'monitoring' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<MonitorForm & { overview: ProjectOverview }>(() => `/projects/${route.params.project}/monitoring/monitors/create`);
const type = computed(() => (typeof route.query.check_type === 'string' && route.query.check_type in data.value.types ? route.query.check_type : 'http'));
const environments = computed(() => data.value.overview.environments.map((environment) => ({ value: environment.id, label: environment.name })));
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Add a monitor')" :description="t('Only monitor endpoints, domains and jobs you own or are allowed to check.')" />
        <MonitorForm :form="data" :project-id="data.overview.project.id" :environments="environments" :type="type" />
    </div>
</template>
