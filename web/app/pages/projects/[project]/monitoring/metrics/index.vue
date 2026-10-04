<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';

/** The project's metric series, filtered in the address, and OpenTelemetry Collector setups that send them. */
definePageMeta({ layout: 'app', service: 'monitoring' });
type MetricsPage = {
    overview: ProjectOverview;
    series: Array<{ id: number; name: string; environment: string; resource: string; kind: string; unit: string | null; lastReceivedAt: string }>;
    page: number;
    lastPage: number;
    filters: { q?: string; environment?: string; kind?: string };
    kinds: Record<string, string>;
    profiles: Record<string, { label: string; stability: string; requirements: string; yaml: string }>;
    canManage: boolean;
};
const { t, dateTime } = useT();
const route = useRoute();
const text = (value: unknown) => (typeof value === 'string' && value !== '' ? value : undefined);
const { data } = await useApi<MetricsPage>(() => `/projects/${route.params.project}/monitoring/metrics`, () => ({ q: text(route.query.q), environment: text(route.query.environment), kind: text(route.query.kind), page: text(route.query.page) }));
const project = computed(() => data.value.overview.project);
const filters = reactive({ q: data.value.filters.q ?? '', environment: (data.value.filters.environment ?? '') as string | null, kind: (data.value.filters.kind ?? '') as string | null });
const environments = computed(() => data.value.overview.environments.map((item) => ({ value: item.id, label: item.name })));
const kinds = computed(() => Object.entries(data.value.kinds).map(([value, label]) => ({ value, label })));

/** Show the series with the chosen filters, from the first page. */
function apply() {
    navigateTo({ query: Object.fromEntries(Object.entries(filters).filter(([, value]) => value)) });
}
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Metrics')" :description="t('Numeric series from any stack. Each series keeps its host, container, database or custom labels, so unrelated resources are never averaged together.')" />
        <SectionNav section="metrics" :project-id="project.id" />
        <form class="ui-card grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_12rem_12rem_auto] lg:items-end" role="search" @submit.prevent="apply">
            <InputField v-model="filters.q" name="q" type="search" :label="t('Metric, unit or resource')" placeholder="system.memory.usage" maxlength="200" />
            <SelectField v-model="filters.environment" name="environment" :label="t('Environment')" :placeholder="t('All')" :options="environments" />
            <SelectField v-model="filters.kind" name="kind" :label="t('Type')" :placeholder="t('All')" :options="kinds" />
            <UiButton type="submit">{{ t('Filter') }}</UiButton>
        </form>
        <SavedViews page="monitoring.metrics" :keys="['environment', 'kind', 'q']" :project="project.id" />

        <DataTable :caption="t('Metric series')">
            <template #head>
                <tr>
                    <th scope="col">{{ t('Metric') }}</th><th scope="col">{{ t('Resource') }}</th><th scope="col">{{ t('Type / unit') }}</th><th scope="col">{{ t('Last received') }}</th>
                    <th v-if="data.canManage" scope="col"><span class="sr-only">{{ t('Actions') }}</span></th>
                </tr>
            </template>
            <tr v-for="item in data.series" :key="item.id">
                <td class="min-w-48">
                    <NuxtLink :to="`/projects/${project.id}/monitoring/metrics/${item.id}`" class="break-all font-bold text-primary hover:underline">{{ item.name }}</NuxtLink>
                    <span class="block text-xs text-muted">{{ item.environment }}</span>
                </td>
                <td class="max-w-xs break-all font-mono text-xs">{{ item.resource }}</td>
                <td class="whitespace-nowrap">{{ item.kind }} <span class="text-muted">{{ item.unit || t('unitless') }}</span></td>
                <td class="whitespace-nowrap text-muted">{{ dateTime(item.lastReceivedAt) }}</td>
                <td v-if="data.canManage" class="whitespace-nowrap">
                    <NuxtLink :to="{ path: `/projects/${project.id}/monitoring/rules/create`, query: { metric: 'numeric_metric', series: String(item.id) } }" class="font-bold text-primary hover:underline">{{ t('Create alert') }}</NuxtLink>
                </td>
            </tr>
            <tr v-if="data.series.length === 0"><td :colspan="data.canManage ? 5 : 4" class="py-10 text-center text-muted">{{ t('No metric series yet. Send OTLP metrics (a collector setup is below) or JSON events with a numeric value.') }}</td></tr>
        </DataTable>
        <Pager :page="data.page" :last-page="data.lastPage" />

        <section class="grid gap-4" aria-labelledby="collector-setups">
            <div>
                <h2 id="collector-setups" class="text-xl font-extrabold text-ink">{{ t('Collector setups') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ t('OpenTelemetry Collector configurations that send metrics to this project as OTLP JSON. Set BEACON_INGEST_TOKEN to an ingest key from the Setup page, and the other variables as secrets.') }}</p>
            </div>
            <div class="ui-card overflow-hidden">
                <ul class="divide-y divide-line">
                    <li v-for="(profile, key) in data.profiles" :key="key">
                        <details class="px-5 py-4">
                            <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-3">
                                <span class="font-bold text-ink">{{ profile.label }}</span>
                                <Badge tone="neutral">{{ profile.stability }}</Badge>
                            </summary>
                            <p class="mt-3 text-sm leading-6 text-muted">{{ profile.requirements }}</p>
                            <CodeBlock class="mt-3 max-h-96 overflow-auto" :code="profile.yaml" />
                        </details>
                    </li>
                </ul>
            </div>
        </section>
    </div>
</template>
