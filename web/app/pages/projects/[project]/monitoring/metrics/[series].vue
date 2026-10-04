<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';

/** One metric series: its values (or rate) over a range as a line, unusual shifts, the latest samples and its labels. */
definePageMeta({ layout: 'app', service: 'monitoring' });
type ChartPoint = { value: number | null; state: string; time: string; event_id: number; x: number; y: number | null; anomaly?: { state: string } };
type SeriesPage = {
    overview: ProjectOverview;
    series: { id: number; name: string; environment: string; resource: string; unit: string | null; kind: string; temporality: string | null; supportsRate: boolean; descriptor: string };
    chart: { points: ChartPoint[]; truncated: boolean; minimum: number | null; maximum: number | null; latest: ChartPoint | null; valid: number; anomalies: number; max_anomaly_score: number | null; anomaly_baseline_points: number };
    chartLimit: number;
    minimumBaseline: number;
    filters: { range: string; mode: string };
    from: string;
    until: string;
    ranges: Record<string, string>;
    anomalies: boolean;
    canManage: boolean;
};
const { t, tc, number, dateTime, locale } = useT();
const route = useRoute();
const text = (value: unknown) => (typeof value === 'string' && value !== '' ? value : undefined);
const { data } = await useApi<SeriesPage>(() => `/projects/${route.params.project}/monitoring/metrics/${route.params.series}`, () => ({ range: text(route.query.range), mode: text(route.query.mode) }));
const project = computed(() => data.value.overview.project);
const series = computed(() => data.value.series);
const chart = computed(() => data.value.chart);
const range = ref<string | null>(data.value.filters.range);
const mode = ref<string | null>(data.value.filters.mode);
const ranges = computed(() => Object.entries(data.value.ranges).map(([value, label]) => ({ value, label })));
const modes = computed(() => [{ value: 'value', label: t('Recorded value') }, ...(series.value.supportsRate ? [{ value: 'rate', label: t('Rate per second') }] : [])]);
const valid = computed(() => chart.value.points.filter((point) => point.value !== null && point.y !== null));
const polyline = computed(() => valid.value.map((point) => `${point.x},${point.y}`).join(' '));
const format = (value: number | null | undefined) => (value === null || value === undefined ? '—' : new Intl.NumberFormat(locale.value, { maximumFractionDigits: 4 }).format(value));
const unusual = (point: ChartPoint) => data.value.anomalies && point.anomaly?.state === 'anomaly';
const latestSamples = computed(() => [...chart.value.points].reverse().slice(0, 50));
const states = computed<Record<string, string>>(() => ({
    valid: t('Valid'),
    unsupported_rate: t('Rate not supported'),
    unknown_interval: t('Unknown interval'),
    missing_baseline: t('No earlier sample'),
    counter_reset: t('Counter reset'),
    invalid_time: t('Invalid time'),
    no_value: t('No value'),
    out_of_range: t('Out of range'),
    conflict: t('Conflicting values'),
}));
const anomalyMessage = computed(() => {
    if (chart.value.anomalies > 0) {
        return t('The largest shift scored :score× the rolling deviation threshold. Treat marked points as leads, not incidents.', { score: format(chart.value.max_anomaly_score) });
    }
    return chart.value.anomaly_baseline_points < data.value.minimumBaseline
        ? t('Not enough history in this range to set a baseline yet. Try a longer range.')
        : t('Nothing in this range moved beyond the rolling baseline.');
});
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader
            :overview="data.overview"
            :title="series.name"
            :description="[series.environment, series.resource, series.unit || t('unitless'), series.temporality ? `${series.kind} / ${series.temporality}` : series.kind].join(' · ')"
        >
            <template #actions>
                <UiButton :to="`/projects/${project.id}/monitoring/metrics`" variant="quiet" size="sm">{{ t('All metrics') }}</UiButton>
                <UiButton v-if="data.canManage" :to="{ path: `/projects/${project.id}/monitoring/rules/create`, query: { metric: 'numeric_metric', series: String(series.id) } }" variant="primary" size="sm">{{ t('Create alert') }}</UiButton>
            </template>
        </ProjectHeader>
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="navigateTo({ query: { range: range ?? undefined, mode: mode === 'value' ? undefined : mode ?? undefined } })">
            <SelectField v-model="range" name="range" :label="t('Range')" :options="ranges" />
            <SelectField v-model="mode" name="mode" :label="t('Show')" :options="modes" />
            <UiButton type="submit">{{ t('Update') }}</UiButton>
        </form>

        <section class="ui-card p-5" aria-labelledby="series-chart">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 id="series-chart" class="font-extrabold text-ink">{{ data.filters.mode === 'rate' ? t('Rate over time') : t('Value over time') }}</h2>
                    <p class="mt-0.5 text-xs text-muted">{{ tc(':count usable point|:count usable points', chart.valid, { count: number(chart.valid) }) }} · {{ dateTime(data.from) }} – {{ dateTime(data.until) }}</p>
                </div>
                <Badge v-if="chart.truncated" tone="warning">{{ t('Showing the latest :count points', { count: data.chartLimit }) }}</Badge>
            </div>
            <template v-if="polyline !== ''">
                <div class="mt-5 overflow-x-auto rounded-control bg-surface-muted p-3">
                    <svg viewBox="0 0 1000 200" class="h-64 w-full min-w-[640px]" role="img" :aria-label="t(':metric from :min to :max; latest :latest', { metric: series.name, min: format(chart.minimum), max: format(chart.maximum), latest: format(chart.latest?.value) })">
                        <line v-for="y in [20, 100, 180]" :key="y" x1="0" :y1="y" x2="1000" :y2="y" class="stroke-line" stroke-width="1" />
                        <polyline :points="polyline" fill="none" class="stroke-chart-1" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                        <circle v-for="point in valid" :key="point.time" :cx="point.x" :cy="point.y ?? 0" :r="unusual(point) ? 5 : 8" :class="unusual(point) ? 'fill-warning stroke-surface' : 'fill-transparent'" stroke-width="2">
                            <title>{{ dateTime(point.time) }}: {{ format(point.value) }}</title>
                        </circle>
                    </svg>
                </div>
                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-3">
                    <div><dt class="text-xs text-muted">{{ t('Lowest') }}</dt><dd class="mt-1 font-bold text-ink tabular-nums">{{ format(chart.minimum) }}</dd></div>
                    <div><dt class="text-xs text-muted">{{ t('Highest') }}</dt><dd class="mt-1 font-bold text-ink tabular-nums">{{ format(chart.maximum) }}</dd></div>
                    <div><dt class="text-xs text-muted">{{ t('Latest') }}</dt><dd class="mt-1 font-bold text-ink tabular-nums">{{ format(chart.latest?.value) }}</dd></div>
                </dl>
            </template>
            <p v-else class="mt-5 rounded-control bg-surface-muted p-8 text-center text-sm text-muted">{{ t('No usable points in this range. Missing values, unsupported distributions and counter resets are listed below.') }}</p>
        </section>

        <Alert v-if="data.anomalies" :tone="chart.anomalies > 0 ? 'warning' : 'info'">
            <p class="font-bold">{{ chart.anomalies > 0 ? tc(':count unusual point|:count unusual points', chart.anomalies) : t('No unusual shifts') }}</p>
            <p class="mt-1 text-sm">{{ anomalyMessage }}</p>
        </Alert>
        <Alert v-else tone="info">{{ t('Anomaly detection marks unusual shifts against a rolling baseline, with no threshold to tune. It comes with Monitoring Pro and above.') }}</Alert>

        <DataTable :caption="t('Latest samples')">
            <template #head><tr><th scope="col">{{ t('Time') }}</th><th scope="col">{{ t('Value') }}</th><th scope="col">{{ t('State') }}</th><th scope="col">{{ t('Event') }}</th></tr></template>
            <tr v-for="point in latestSamples" :key="`${point.time}-${point.event_id}`">
                <td class="whitespace-nowrap">{{ dateTime(point.time) }}</td>
                <td class="font-mono">{{ format(point.value) }}</td>
                <td>{{ states[point.state] ?? point.state }}</td>
                <td><NuxtLink :to="`/projects/${project.id}/monitoring/events/${point.event_id}`" class="font-bold text-primary hover:underline">{{ t('Open') }}</NuxtLink></td>
            </tr>
            <tr v-if="latestSamples.length === 0"><td colspan="4" class="py-8 text-center text-muted">{{ t('No samples in this range.') }}</td></tr>
        </DataTable>

        <SettingsSection :title="t('Resource identity')" :description="t('Resource attributes and point labels, kept separately. Sensitive values are redacted.')">
            <CodeBlock class="m-4 max-h-96 overflow-auto sm:m-6" :code="series.descriptor" />
        </SettingsSection>
    </div>
</template>
