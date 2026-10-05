<script setup lang="ts">
import type { ReleaseMetrics } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';
import type { Tone } from '~/types/ui';

/** One release: its traffic and errors over a range, the issues it raised and where it was deployed. */
definePageMeta({ layout: 'app', service: 'monitoring' });
type ReleasePage = {
    overview: ProjectOverview;
    release: { id: number; version: string; service: string };
    filters: { range: string; environment?: string };
    metrics: ReleaseMetrics;
    issues: Array<{ id: number; title: string; statusLabel: string; statusTone: Tone }>;
    deployments: Array<{ id: number; environment: string; actor: string | null; deployedAt: string }>;
    ranges: Record<string, string>;
};
const { t, dateTime } = useT();
const route = useRoute();
const text = (value: unknown) => (typeof value === 'string' && value !== '' ? value : undefined);
const { data } = await useApi<ReleasePage>(() => `/projects/${route.params.project}/monitoring/releases/${route.params.release}`, () => ({ range: text(route.query.range), environment: text(route.query.environment) }));
const project = computed(() => data.value.overview.project);
const range = ref<string | null>(data.value.filters.range);
const environment = ref<string | null>(data.value.filters.environment ?? '');
const ranges = computed(() => Object.entries(data.value.ranges).map(([value, label]) => ({ value, label })));
const environments = computed(() => data.value.overview.environments.map((item) => ({ value: item.id, label: item.name })));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="data.release.version" :description="data.release.service" />
        <form class="ui-card flex flex-wrap items-end gap-3 p-4" @submit.prevent="navigateTo({ query: { range: range ?? undefined, environment: environment || undefined } })">
            <SelectField v-model="range" name="range" :label="t('Time')" :options="ranges" />
            <SelectField v-model="environment" name="environment" :label="t('Environment')" :placeholder="t('All')" :options="environments" />
            <AcmeBtn type="submit">{{ t('Show') }}</AcmeBtn>
        </form>
        <ReleaseMetricCards :metrics="data.metrics" />
        <DataTable :caption="t('Issues in this release')">
            <template #head><tr><th scope="col">{{ t('Issue') }}</th><th scope="col">{{ t('Status') }}</th></tr></template>
            <tr v-for="issue in data.issues" :key="issue.id">
                <td><NuxtLink :to="`/projects/${project.id}/monitoring/issues/${issue.id}`" class="text-primary hover:underline">{{ issue.title }}</NuxtLink></td>
                <td><AcmeBadge :tone="acmeTone(issue.statusTone)">{{ issue.statusLabel }}</AcmeBadge></td>
            </tr>
            <tr v-if="data.issues.length === 0"><td colspan="2" class="py-8 text-center text-muted">{{ t('No exceptions from this release in this range.') }}</td></tr>
        </DataTable>
        <DataTable :caption="t('Deployments')">
            <template #head><tr><th scope="col">{{ t('When') }}</th><th scope="col">{{ t('Environment') }}</th><th scope="col">{{ t('By') }}</th></tr></template>
            <tr v-for="deployment in data.deployments" :key="deployment.id">
                <td class="whitespace-nowrap"><NuxtLink :to="`/projects/${project.id}/monitoring/deployments/${deployment.id}`" class="text-primary hover:underline">{{ dateTime(deployment.deployedAt) }}</NuxtLink></td>
                <td>{{ deployment.environment }}</td>
                <td>{{ deployment.actor ?? t('Pipeline') }}</td>
            </tr>
            <tr v-if="data.deployments.length === 0"><td colspan="3" class="py-8 text-center text-muted">{{ t('No deployments recorded for this release.') }}</td></tr>
        </DataTable>
    </div>
</template>
