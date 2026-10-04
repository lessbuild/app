<script setup lang="ts">
import type { MonitorForm } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/** Add a monitor: pick what kind (`?check_type=`), then fill in what it checks. */
definePageMeta({ layout: 'app', service: 'monitoring' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<MonitorForm & { overview: ProjectOverview }>(() => `/projects/${route.params.project}/monitoring/monitors/create`);
const type = computed(() => (typeof route.query.check_type === 'string' && route.query.check_type in data.value.types ? route.query.check_type : 'http'));
const environments = computed(() => data.value.overview.environments.map((environment) => ({ value: environment.id, label: environment.name })));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Add a monitor')" :description="t('Only monitor endpoints, domains and jobs you own or are allowed to check.')" />
        <nav :aria-label="t('Monitor type')" class="flex flex-wrap gap-2">
            <UiButton v-for="(label, key) in data.types" :key="key" :to="{ query: { check_type: String(key) } }" :variant="type === key ? 'soft' : 'secondary'" size="sm" :aria-current="type === key ? 'page' : undefined">
                {{ label }}
            </UiButton>
        </nav>
        <section class="ui-card p-4 sm:p-6">
            <MonitorForm :key="type" :form="data" :project-id="data.overview.project.id" :environments="environments" :type="type" />
        </section>
    </div>
</template>
