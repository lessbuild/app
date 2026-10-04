<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';

/** Add a service level objective. */
definePageMeta({ layout: 'app', service: 'monitoring' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview }>(() => `/projects/${route.params.project}/monitoring/objectives/create`);
const environments = computed(() => data.value.overview.environments.map((environment) => ({ value: environment.id, label: environment.name })));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Add an objective')" :description="t('Measured from request events in one environment.')" />
        <section class="ui-card p-4 sm:p-6"><ObjectiveForm :objective="null" :project-id="data.overview.project.id" :environments="environments" /></section>
    </div>
</template>
