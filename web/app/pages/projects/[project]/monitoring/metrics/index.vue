<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';

/**
 * The project's metric series (the Acme theme's metrics page): each with a sparkline of its last day and its latest
 * value, filtered in the address, and the OpenTelemetry Collector setups that send them.
 */
definePageMeta({ layout: 'app', service: 'monitoring' });
type MetricsPage = {
    overview: ProjectOverview;
    series: Array<{ id: number; name: string; environment: string; resource: string; kind: string; unit: string | null; lastReceivedAt: string; values: number[]; latest: number | null }>;
    page: number;
    lastPage: number;
    filters: { q?: string; environment?: string; kind?: string };
    kinds: Record<string, string>;
    profiles: Record<string, { label: string; stability: string; requirements: string; yaml: string }>;
    canManage: boolean;
};
const { t, number } = useT();
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
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Monitoring')" :description="t('Numbers your apps report, from any stack. Each series keeps its host, container, database or custom labels, so unrelated resources are never averaged together.')">
            <template #actions>
                <AcmeBtn variant="primary" icon="plus" :to="{ path: `/projects/${project.id}/monitoring/dashboards`, query: { dialog: 'add-dashboard' } }">{{ t('New dashboard') }}</AcmeBtn>
            </template>
        </ProjectHeader>
        <div class="space-y-6">
        <SectionNav section="metrics" :project-id="project.id" />
        <AcmeCard :padded="false">
            <form class="flex flex-wrap items-end gap-3 px-5 pt-5 sm:px-6" role="search" @submit.prevent="apply">
                <h2 class="mr-auto font-semibold text-ink">{{ t('Series') }}</h2>
                <SelectField v-model="filters.environment" name="environment" :label="t('Environment')" :placeholder="t('All environments')" :options="environments" hide-label class="w-44" @update:model-value="apply" />
                <SelectField v-model="filters.kind" name="kind" :label="t('Type')" :placeholder="t('All types')" :options="kinds" hide-label class="w-36" @update:model-value="apply" />
                <AcmeSearchInput v-model="filters.q" :label="t('Metric, unit or resource')" :placeholder="t('Search metrics')" class="w-full sm:w-64" />
            </form>
            <div class="px-5 pt-3 sm:px-6"><SavedViews page="monitoring.metrics" :keys="['environment', 'kind', 'q']" :project="project.id" /></div>
            <ul v-if="data.series.length > 0" class="mt-4 divide-y divide-line border-t border-line">
                <li v-for="item in data.series" :key="item.id" class="grid gap-3 px-5 py-4 sm:grid-cols-[1fr_10rem_7rem_auto] sm:items-center sm:px-6">
                    <NuxtLink :to="`/projects/${project.id}/monitoring/metrics/${item.id}`" class="min-w-0 hover:underline">
                        <b class="block truncate font-mono text-sm font-medium text-ink">{{ item.name }}</b>
                        <span class="block truncate text-xs text-muted">{{ item.resource }} · {{ item.kind }} · {{ item.environment }}</span>
                    </NuxtLink>
                    <AcmeSparkline v-if="item.values.length > 1" :values="item.values" :label="t(':metric, last day', { metric: item.name })" area />
                    <span v-else class="text-xs text-muted">—</span>
                    <span class="text-right">
                        <b class="tabular-nums text-ink">{{ item.latest === null ? '—' : number(item.latest) }}</b>
                        <span class="block text-xs text-muted">{{ item.unit || t('unitless') }} · <RelativeTime :at="item.lastReceivedAt" /></span>
                    </span>
                    <AcmeBtn v-if="data.canManage" size="sm" variant="ghost" icon="bell" :label="t('Create alert')" :to="{ path: `/projects/${project.id}/monitoring/rules/create`, query: { metric: 'numeric_metric', series: String(item.id) } }" />
                </li>
            </ul>
            <p v-else class="px-5 py-10 text-center text-sm text-muted sm:px-6">{{ t('No metric series yet. Send OTLP metrics (a collector setup is below) or JSON events with a numeric value.') }}</p>
        </AcmeCard>
        <Pager :page="data.page" :last-page="data.lastPage" />

        <AcmeCard :title="t('Collector setups')" :description="t('OpenTelemetry Collector configurations that send metrics to this project as OTLP JSON. Set BEACON_INGEST_TOKEN to an ingest key from the Setup page, and the other variables as secrets.')" :padded="false">
                <ul class="divide-y divide-line border-t border-line">
                    <li v-for="(profile, key) in data.profiles" :key="key">
                        <details class="px-5 py-4">
                            <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-3">
                                <span class="font-medium text-ink">{{ profile.label }}</span>
                                <AcmeBadge tone="gray">{{ profile.stability }}</AcmeBadge>
                            </summary>
                            <p class="mt-3 text-sm leading-6 text-muted">{{ profile.requirements }}</p>
                            <CodeBlock class="mt-3 max-h-96 overflow-auto" :code="profile.yaml" />
                        </details>
                    </li>
                </ul>
        </AcmeCard>
        </div>
    </div>
</template>
