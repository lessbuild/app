<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';

/** Which services call which, built from trace spans over a time range. */
definePageMeta({ layout: 'app', service: 'monitoring' });
type ServiceRow = { name: string; span_count: number; error_count: number; error_rate: number; average_duration: number | null };
type EdgeRow = { source: string; target: string; calls: number; trace_count: number; error_count: number; error_rate: number; average_duration: number | null; max_duration: number | null };
type DependenciesPage = {
    overview: ProjectOverview;
    map: { records: number; traces: number; services: ServiceRow[]; edges: EdgeRow[]; service_count: number; dependency_count: number; truncated: boolean };
    filters: { range: string; environment?: string };
    ranges: Record<string, string>;
};
const { t, number, locale } = useT();
const route = useRoute();
const text = (value: unknown) => (typeof value === 'string' && value !== '' ? value : undefined);
const { data } = await useApi<DependenciesPage>(() => `/projects/${route.params.project}/monitoring/dependencies`, () => ({ range: text(route.query.range), environment: text(route.query.environment) }));
const range = ref<string | null>(data.value.filters.range);
const environment = ref<string | null>(data.value.filters.environment ?? '');
const ranges = computed(() => Object.entries(data.value.ranges).map(([value, label]) => ({ value, label })));
const environments = computed(() => data.value.overview.environments.map((item) => ({ value: item.id, label: item.name })));
const decimal = (value: number) => new Intl.NumberFormat(locale.value, { maximumFractionDigits: 1, minimumFractionDigits: 1 }).format(value);
const ms = (value: number | null) => (value === null ? '—' : `${decimal(value)} ms`);

/** Show the map for the chosen range and environment. */
function apply() {
    navigateTo({ query: { range: range.value ?? undefined, environment: environment.value || undefined } });
}
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Monitoring')" :description="t('Everything your apps sent: search it, follow a trace, see which services call which.')" />
        <div class="space-y-6">
            <AcmeCard :padded="false">
                <div class="px-5 pt-4 sm:px-6"><EventsTabs :project-id="data.overview.project.id" current="map" /></div>
                <div class="grid gap-5 p-5 sm:p-6">
                    <form class="flex flex-wrap items-end gap-3" @submit.prevent="apply">
                        <SelectField v-model="range" name="range" :label="t('Time')" :options="ranges" />
                        <SelectField v-model="environment" name="environment" :label="t('Environment')" :placeholder="t('All')" :options="environments" />
                        <AcmeBtn type="submit">{{ t('Show') }}</AcmeBtn>
                        <p class="ml-auto text-sm text-muted">{{ t(':services services · :dependencies dependencies · :traces traces', { services: number(data.map.service_count), dependencies: number(data.map.dependency_count), traces: number(data.map.traces) }) }}</p>
                    </form>
                    <AcmeAlert v-if="data.map.truncated" tone="warning">{{ t('Only the first :count spans are included. Pick a shorter time range for a complete map.', { count: number(20000) }) }}</AcmeAlert>
                    <ServiceMap v-if="data.map.services.length > 0" :services="data.map.services" :edges="data.map.edges" :label="t('Service map')" />
                    <AcmeEmptyState v-else icon="branch" :title="t('No spans in this range.')" :description="t('No calls between services in this range. Spans need a parent in another service to appear here.')" />
                    <DataTable v-if="data.map.edges.length > 0" :caption="t('Calls between services')" :framed="false">
                        <template #head>
                            <tr><th scope="col">{{ t('From') }}</th><th scope="col">{{ t('To') }}</th><th scope="col" class="text-right">{{ t('Calls') }}</th><th scope="col" class="text-right">{{ t('Errors') }}</th><th scope="col" class="text-right">{{ t('Average') }}</th></tr>
                        </template>
                        <tr v-for="edge in data.map.edges" :key="`${edge.source}->${edge.target}`">
                            <td class="font-medium text-ink">{{ edge.source }}</td>
                            <td><span aria-hidden="true" class="text-muted">→ </span>{{ edge.target }}</td>
                            <td class="text-right tabular-nums">{{ number(edge.calls) }}</td>
                            <td :class="['text-right tabular-nums', edge.error_rate > 1 && 'text-rose-600 dark:text-rose-400']">{{ decimal(edge.error_rate) }}%</td>
                            <td class="whitespace-nowrap text-right tabular-nums">{{ ms(edge.average_duration) }}</td>
                        </tr>
                    </DataTable>
                </div>
            </AcmeCard>
            <AcmeCard v-if="data.map.services.length > 0" :title="t('Services')" :padded="false">
                <DataTable :caption="t('Services')" :framed="false">
                    <template #head>
                        <tr><th scope="col">{{ t('Service') }}</th><th scope="col" class="text-right">{{ t('Spans') }}</th><th scope="col" class="text-right">{{ t('Errors') }}</th><th scope="col" class="text-right">{{ t('Average') }}</th></tr>
                    </template>
                    <tr v-for="service in data.map.services" :key="service.name">
                        <td class="font-medium text-ink">{{ service.name }}</td>
                        <td class="text-right tabular-nums">{{ number(service.span_count) }}</td>
                        <td :class="['text-right tabular-nums', service.error_rate > 1 && 'text-rose-600 dark:text-rose-400']">{{ decimal(service.error_rate) }}%</td>
                        <td class="whitespace-nowrap text-right tabular-nums">{{ ms(service.average_duration) }}</td>
                    </tr>
                </DataTable>
            </AcmeCard>
        </div>
    </div>
</template>
