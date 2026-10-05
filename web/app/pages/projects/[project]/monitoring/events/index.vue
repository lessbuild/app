<script setup lang="ts">
import type { EventRow } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/**
 * Every request, query, job, log, exception and metric the project's environments sent (the Acme theme's event stream),
 * filtered by kind and text, with more filters a click away (all kept in the address).
 */
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

const kind = computed({ get: () => filters.type ?? '', set: (value: string | number) => { filters.type = String(value); apply(); } });
const kinds = computed(() => [{ value: '', label: t('All') }, ...options(data.value.types)]);
const icon: Record<string, string> = { request: 'globe', exception: 'flame', query: 'code', job: 'layers', log: 'list', metric: 'trending', span: 'branch' };
const dot: Record<string, string> = { danger: 'bg-rose-500', warning: 'bg-amber-500', neutral: 'bg-zinc-400' };
/**
 * Whether an event took long enough to stand out (over 800 ms).
 *
 * @param duration The duration as shown, such as "42 ms" or "1.2 s".
 */
function slow(duration: string): boolean {
    const value = Number.parseFloat(duration.replace(/[^\d.,]/g, '').replace(',', '.'));
    return duration.includes('ms') ? value > 800 : /\d\s?s\b/.test(duration) && value >= 0.8;
}
const more = ref(['environment', 'severity', 'range', 'sort', 'trace'].some((key) => data.value.filters[key] && !(key === 'range' || key === 'sort')));

/** Show the events with the chosen filters, from the first page. */
function apply() {
    navigateTo({ query: Object.fromEntries(Object.entries(filters).filter(([, value]) => value)) });
}
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Monitoring')" :description="t('Everything your apps sent: search it, follow a trace, see which services call which.')" />
        <div class="space-y-6">
            <AcmeCard :padded="false">
                <div class="px-5 pt-4 sm:px-6"><EventsTabs :project-id="project.id" current="stream" /></div>
                <form class="grid gap-3 px-5 pt-4 sm:px-6" role="search" @submit.prevent="apply">
                    <div class="flex flex-wrap items-center gap-3">
                        <AcmeSegmented v-model="kind" :options="kinds" :label="t('Kind')" size="sm" />
                        <AcmeBtn size="sm" variant="ghost" icon="filter" :aria-expanded="more" @click="more = !more">{{ t('More filters') }}</AcmeBtn>
                        <AcmeSearchInput v-model="filters.q" :label="t('Search')" :placeholder="t('Search events')" class="ml-auto w-full sm:w-64" />
                    </div>
                    <div v-show="more" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
                        <SelectField v-model="filters.environment" name="environment" :label="t('Environment')" :placeholder="t('All')" :options="environments" />
                        <SelectField v-model="filters.severity" name="severity" :label="t('Severity')" :placeholder="t('All')" :options="options(data.severities)" />
                        <SelectField v-model="filters.range" name="range" :label="t('Time')" :options="options(data.ranges)" />
                        <SelectField v-model="filters.sort" name="sort" :label="t('Order')" :options="options(data.sorts)" />
                        <InputField v-model="filters.trace" name="trace" :label="t('Trace ID')" maxlength="64" />
                        <div class="lg:col-span-5"><AcmeBtn type="submit" size="sm">{{ t('Filter') }}</AcmeBtn></div>
                    </div>
                </form>
                <div class="px-5 pt-3 sm:px-6"><SavedViews page="monitoring.events" :keys="['environment', 'q', 'range', 'severity', 'sort', 'trace', 'type']" :project="project.id" /></div>
                <div class="mt-4 border-t border-line">
                    <DataTable :caption="t('Events')" :framed="false">
                        <template #head>
                            <tr><th scope="col">{{ t('Time') }}</th><th scope="col">{{ t('Event') }}</th><th scope="col">{{ t('Service') }}</th><th scope="col" class="text-right">{{ t('Duration') }}</th><th scope="col">{{ t('Trace') }}</th></tr>
                        </template>
                        <tr v-for="event in data.events" :key="event.id">
                            <td class="whitespace-nowrap font-mono text-xs text-muted">
                                <span :class="['mr-2 inline-block size-2 rounded-full', dot[event.tone] ?? 'bg-zinc-400']" aria-hidden="true" />
                                <NuxtLink :to="`/projects/${project.id}/monitoring/events/${event.id}`" class="hover:underline">{{ dateTime(event.occurredAt) }}</NuxtLink>
                            </td>
                            <td class="min-w-56">
                                <span class="flex items-center gap-2">
                                    <AcmeIcon :name="icon[event.type] ?? 'circle'" :size="14" class="shrink-0 text-muted" :title="event.typeLabel" />
                                    <span class="truncate font-mono text-xs text-ink">{{ event.name }}</span>
                                    <AcmeBadge v-if="event.statusCode" :tone="event.statusCode >= 500 ? 'red' : event.statusCode >= 400 ? 'amber' : 'green'">{{ event.statusCode }}</AcmeBadge>
                                </span>
                                <span v-if="event.environment" class="text-xs text-muted">{{ event.environment }}</span>
                            </td>
                            <td class="text-muted">{{ event.service }}</td>
                            <td :class="['whitespace-nowrap text-right tabular-nums', slow(event.duration) && 'font-medium text-amber-600 dark:text-amber-400']">{{ event.duration }}</td>
                            <td>
                                <NuxtLink v-if="event.traceId" :to="`/projects/${project.id}/monitoring/traces/${event.traceId}`" class="font-mono text-xs text-accent hover:underline">{{ event.traceId.slice(0, 12) }}</NuxtLink>
                                <span v-else class="text-muted">—</span>
                            </td>
                        </tr>
                        <tr v-if="data.events.length === 0"><td colspan="5" class="py-10 text-center text-muted">{{ t('No events match. Try a longer time range, or connect an app on the Setup page.') }}</td></tr>
                    </DataTable>
                </div>
            </AcmeCard>
            <Pager :page="data.page" :last-page="data.lastPage" />
        </div>
    </div>
</template>
