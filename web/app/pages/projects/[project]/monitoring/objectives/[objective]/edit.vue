<script setup lang="ts">
import type { ObjectiveSettings } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/** Change a service level objective. */
definePageMeta({ layout: 'app', service: 'monitoring' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview; objective: ObjectiveSettings }>(() => `/projects/${route.params.project}/monitoring/objectives/${route.params.objective}/edit`);
const environments = computed(() => data.value.overview.environments.map((environment) => ({ value: environment.id, label: environment.name })));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Edit :objective', { objective: data.objective.name })" :description="t('Measured from request events in one environment.')" />
        <section class="ui-card p-4 sm:p-6"><ObjectiveForm :objective="data.objective" :project-id="data.overview.project.id" :environments="environments" /></section>
    </div>
</template>
