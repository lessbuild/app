<script setup lang="ts">
import type { AlertRuleForm } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/** Add an alert rule; `?metric=` and `?series=` preselect what it watches, as the metric explorer links do. */
definePageMeta({ layout: 'app', service: 'monitoring' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<AlertRuleForm & { overview: ProjectOverview }>(() => `/projects/${route.params.project}/monitoring/rules/create`);
const environments = computed(() => data.value.overview.environments.map((environment) => ({ value: environment.id, label: environment.name })));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Add an alert rule')" :description="t('A rule watches one environment and, optionally, one service.')" />
        <section class="ui-card px-5 pb-5 sm:px-6 sm:pb-6"><RuleForm :form="data" :project-id="data.overview.project.id" :environments="environments" /></section>
    </div>
</template>
