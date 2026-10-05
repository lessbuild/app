<script setup lang="ts">
import type { EventRow } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';
import type { Tone } from '~/types/ui';

/** Every request, query, job, log, exception and metric the project's environments sent, filtered in the address. */
definePageMeta({ layout: 'app', service: 'monitoring' });
type EventsPage = {
    overview: ProjectOverview;
    events: EventRow[];
    page: number;
    lastPage: number;
    filters: Record<string, string | undefined> & { range: string; sort: string };
    types: Record<string, string>;
    severities: Record<string, string>;
    ranges: Record<string, string>;
    sorts: Record<string, string>;
};
const keys = ['q', 'environment', 'type', 'severity', 'range', 'sort', 'trace', 'service', 'release', 'status', 'has_trace', 'min_duration'] as const;
const { t, dateTime } = useT();
const route = useRoute();
const text = (value: unknown) => (typeof value === 'string' && value !== '' ? value : undefined);
const { data } = await useApi<EventsPage>(() => `/projects/${route.params.project}/monitoring/events`, () => ({ ...Object.fromEntries(keys.map((key) => [key, text(route.query[key])])), page: text(route.query.page) }));
const project = computed(() => data.value.overview.project);
const filters = reactive<Record<string, string | null>>(Object.fromEntries(keys.map((key) => [key, data.value.filters[key] ?? (['environment', 'type', 'severity'].includes(key) ? '' : null)])));
const options = (labels: Record<string, string>) => Object.entries(labels).map(([value, label]) => ({ value, label }));
const environments = computed(() => data.value.overview.environments.map((environment) => ({ value: environment.id, label: environment.name })));

/** Show the events with the chosen filters, from the first page. */
function apply() {
    navigateTo({ query: Object.fromEntries(Object.entries(filters).filter(([, value]) => value)) });
}
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Events')" :description="t('Every request, query, job, log, exception and metric your environments sent.')">
            <template #actions><AcmeBtn :to="`/projects/${project.id}/monitoring/dependencies`" size="sm">{{ t('Service map') }}</AcmeBtn></template>
        </ProjectHeader>
        <form class="ui-card grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end" role="search" @submit.prevent="apply">
            <InputField v-model="filters.q" name="q" type="search" :label="t('Search')" maxlength="255" />
            <SelectField v-model="filters.environment" name="environment" :label="t('Environment')" :placeholder="t('All')" :options="environments" />
            <SelectField v-model="filters.type" name="type" :label="t('Type')" :placeholder="t('All')" :options="options(data.types)" />
            <SelectField v-model="filters.severity" name="severity" :label="t('Severity')" :placeholder="t('All')" :options="options(data.severities)" />
            <SelectField v-model="filters.range" name="range" :label="t('Time')" :options="options(data.ranges)" />
            <SelectField v-model="filters.sort" name="sort" :label="t('Order')" :options="options(data.sorts)" />
            <InputField v-model="filters.trace" name="trace" :label="t('Trace ID')" maxlength="64" />
            <AcmeBtn type="submit">{{ t('Filter') }}</AcmeBtn>
        </form>
        <SavedViews page="monitoring.events" :keys="['environment', 'q', 'range', 'severity', 'sort', 'trace', 'type']" :project="project.id" />

        <DataTable :caption="t('Events')">
            <template #head>
                <tr><th scope="col">{{ t('When') }}</th><th scope="col">{{ t('Event') }}</th><th scope="col">{{ t('Service') }}</th><th scope="col">{{ t('Duration') }}</th><th scope="col">{{ t('Trace') }}</th></tr>
            </template>
            <tr v-for="event in data.events" :key="event.id">
                <td class="whitespace-nowrap"><NuxtLink :to="`/projects/${project.id}/monitoring/events/${event.id}`" class="text-primary hover:underline">{{ dateTime(event.occurredAt) }}</NuxtLink></td>
                <td class="min-w-48">
                    <span class="flex flex-wrap items-center gap-2"><AcmeBadge :tone="acmeTone(event.tone as Tone)">{{ event.typeLabel }}</AcmeBadge><span class="break-all">{{ event.name }}</span></span>
                    <span class="text-xs text-muted">{{ event.environment ?? '—' }}<template v-if="event.statusCode"> · {{ event.statusCode }}</template></span>
                </td>
                <td>{{ event.service }}</td>
                <td class="whitespace-nowrap tabular-nums">{{ event.duration }}</td>
                <td>
                    <NuxtLink v-if="event.traceId" :to="`/projects/${project.id}/monitoring/traces/${event.traceId}`" class="text-primary hover:underline">{{ t('View') }}</NuxtLink>
                    <template v-else>—</template>
                </td>
            </tr>
            <tr v-if="data.events.length === 0"><td colspan="5" class="py-10 text-center text-muted">{{ t('No events match. Try a longer time range, or connect an app on the Setup page.') }}</td></tr>
        </DataTable>
        <Pager :page="data.page" :last-page="data.lastPage" />
    </div>
</template>
