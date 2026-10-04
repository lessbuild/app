<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router';
import type { AnalyticsReport, ReportFilters } from '~/types/analytics';

/**
 * A site's report: headline numbers, the chart (with notes and releases beneath it), page speed, Google search terms,
 * engagement, the ranked lists (each row narrows the report to it), goals and the live panel. Shared by the Analytics
 * page and the shared and view-only reports; `link` builds the address of the same report with other filters.
 */
const props = withDefaults(defineProps<{
    report: AnalyticsReport;
    filters: ReportFilters;
    link: (filters: Record<string, string>) => RouteLocationRaw;
    liveUrl: string;
    goalsUrl?: string | null;
    annotations?: Array<{ id: number; date: string; text: string }>;
    releases?: Array<{ id: number; version: string; environment: string; deployedAt: string; fromDeploy: boolean }>;
    notesAction?: string | null;
}>(), { goalsUrl: null, annotations: () => [], releases: () => [], notesAction: null });
const { t, tc, number, date, dateTime } = useT();
const hourly = computed(() => props.report.granularity === 'hour');
const chartLabel = computed(() => (hourly.value ? t('Pageviews per hour') : t('Pageviews per day')));
const points = computed(() => props.report.series.map((point) => ({ label: point.date, value: point.value })));
const activeFilters = computed(() => Object.fromEntries(Object.entries(props.filters).filter((entry): entry is [string, string] => typeof entry[1] === 'string' && entry[1] !== '')));
/** The address of this report narrowed to one more filter (or one page, for the page lists). */
const narrow = (key: string, value: string) => props.link({ ...activeFilters.value, [key]: value });

/** Say how a headline number moved, against what the period is compared with. */
function changeText(change: string | null): string {
    if (change === null) {
        return t('This period');
    }
    if (change === 'New') {
        return t('New this period');
    }
    if (props.report.period.compare === 'year') {
        return t(':change vs last year', { change });
    }
    return hourly.value ? t(':change vs the day before', { change }) : t(':change vs previous period', { change });
}
const changeClass = (change: string | null) => (change === null ? 'text-muted' : change.startsWith('-') ? 'font-bold text-danger' : 'font-bold text-success');

const vitalNames = computed(() => ({
    lcp: [t('Largest Contentful Paint'), t('How long the main content takes to appear')],
    inp: [t('Interaction to Next Paint'), t('How quickly the page responds to taps and clicks')],
    cls: [t('Cumulative Layout Shift'), t('How much the page jumps around while loading')],
    ttfb: [t('Time to First Byte'), t('How long the server takes to answer')],
}));
const ratings = computed(() => ({ good: { label: t('Good'), tone: 'success' as const }, needs_improvement: { label: t('Needs improvement'), tone: 'warning' as const }, poor: { label: t('Poor'), tone: 'danger' as const } }));
/** Format a Core Web Vital: layout shift as a score, times in milliseconds or seconds. */
const vital = (metric: string, value: number) => (metric === 'cls' ? value.toFixed(2) : value >= 1000 ? `${(value / 1000).toFixed(2)} s` : `${number(Math.round(value))} ms`);
/** Format seconds on a page as 1m 05s or 42s. */
const duration = (seconds: number) => (seconds >= 60 ? `${Math.floor(seconds / 60)}m ${String(seconds % 60).padStart(2, '0')}s` : `${seconds}s`);
</script>

<template>
    <div class="space-y-6">
        <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
            <div v-for="metric in report.metrics" :key="metric.label" class="ui-card p-4">
                <p class="text-xs font-bold text-muted">{{ metric.label }}</p>
                <p class="mt-2 text-2xl font-extrabold tracking-tight text-ink tabular-nums">{{ metric.value }}</p>
                <p :class="['mt-1 text-xs', changeClass(metric.change)]">{{ changeText(metric.change) }}</p>
            </div>
        </div>

        <section class="ui-card grid gap-4 p-5 sm:p-6" aria-labelledby="pageviews-heading">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="pageviews-heading" class="text-lg font-extrabold text-ink">{{ chartLabel }}</h2>
                <p class="text-xs text-muted">{{ hourly ? date(report.period.start) : `${date(report.period.start)} – ${date(report.period.end)}` }} · {{ report.timezone }}</p>
            </div>
            <BarChart v-if="report.hasData" :label="chartLabel" :points="points" :unit="` ${t('pageviews')}`" :max="1" />
            <p v-else class="text-sm text-muted">{{ t('No pageviews in this period yet.') }}</p>
            <div v-if="annotations.length > 0" class="border-t border-line pt-4">
                <h3 class="text-sm font-extrabold text-ink">{{ t('Notes') }}</h3>
                <ul class="mt-2 grid gap-1 text-xs text-muted">
                    <li v-for="note in annotations" :key="note.id" class="flex flex-wrap items-center gap-2">
                        <span class="tabular-nums">{{ date(note.date) }}</span> · <span class="text-ink">{{ note.text }}</span>
                        <ApiForm v-if="notesAction" :action="`${notesAction}/${note.id}`" method="DELETE"><button type="submit" class="ui-link text-xs">{{ t('Remove') }}</button></ApiForm>
                    </li>
                </ul>
            </div>
            <div v-if="releases.length > 0" class="border-t border-line pt-4">
                <h3 class="text-sm font-extrabold text-ink">{{ t('Releases in this period') }}</h3>
                <ul class="mt-2 grid gap-1 text-xs text-muted">
                    <li v-for="release in releases" :key="release.id">
                        <span class="tabular-nums">{{ dateTime(release.deployedAt) }}</span> · <span class="font-mono text-ink">{{ release.version }}</span> · {{ release.environment }}<template v-if="release.fromDeploy"> · {{ t('Deploy') }}</template>
                    </li>
                </ul>
            </div>
        </section>

        <section class="ui-card grid gap-4 p-5 sm:p-6" aria-labelledby="speed-heading">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="speed-heading" class="text-lg font-extrabold text-ink">{{ t('Page speed') }}</h2>
                <p class="text-xs text-muted">
                    {{ report.vitals.samples > 0 ? tc('75th percentile of :count page load by real visitors|75th percentile of :count page loads by real visitors', report.vitals.samples, { count: number(report.vitals.samples) }) : t('Core Web Vitals from real visitors') }}
                </p>
            </div>
            <p v-if="report.vitals.samples === 0" class="text-sm text-muted">{{ t('Add data-vitals to the snippet to measure how fast pages load for real visitors, and see whether a release made them slower.') }}</p>
            <template v-else>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div v-for="(names, metric) in vitalNames" :key="metric" class="rounded-control border border-line p-4">
                        <p class="text-xs font-bold text-muted">{{ names[0] }} <span class="uppercase">({{ metric }})</span></p>
                        <p class="mt-2 text-2xl font-extrabold tracking-tight text-ink tabular-nums">{{ report.vitals.metrics[metric]?.value == null ? '—' : vital(metric, report.vitals.metrics[metric]!.value!) }}</p>
                        <Badge v-if="report.vitals.metrics[metric]?.rating" class="mt-2" :tone="ratings[report.vitals.metrics[metric]!.rating!].tone">{{ ratings[report.vitals.metrics[metric]!.rating!].label }}</Badge>
                        <p class="mt-2 text-xs text-muted">{{ names[1] }}</p>
                    </div>
                </div>
                <div v-if="report.vitals.slowPages.length > 0">
                    <h3 class="text-sm font-extrabold text-ink">{{ t('Slowest pages to load') }}</h3>
                    <ul class="mt-2 grid gap-2 text-sm">
                        <li v-for="page in report.vitals.slowPages" :key="page.path" class="flex items-center justify-between gap-4">
                            <NuxtLink :to="narrow('path', page.path)" class="truncate text-muted hover:text-ink hover:underline">{{ page.path }}</NuxtLink>
                            <span class="shrink-0 tabular-nums"><strong class="text-ink">{{ vital('lcp', page.lcp) }}</strong> <span class="text-xs text-muted">· {{ tc(':count load|:count loads', page.samples, { count: page.samples }) }}</span></span>
                        </li>
                    </ul>
                </div>
            </template>
        </section>

        <section v-if="report.searchTerms" class="ui-card grid gap-4 p-5 sm:p-6" aria-labelledby="search-terms-heading">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="search-terms-heading" class="text-lg font-extrabold text-ink">{{ t('Google search terms') }}</h2>
                <p class="text-xs text-muted">{{ t('From Google Search Console (:property). Google’s figures lag by about two days.', { property: report.searchTerms.property }) }}</p>
            </div>
            <Alert v-if="report.searchTerms.error" tone="warning">{{ report.searchTerms.error }}</Alert>
            <p v-else-if="report.searchTerms.rows.length === 0" class="text-sm text-muted">{{ t('No searches led here in this period.') }}</p>
            <DataTable v-else :caption="t('Google search terms')" :framed="false">
                <template #head>
                    <tr><th scope="col">{{ t('Search term') }}</th><th scope="col" class="text-right">{{ t('Clicks') }}</th><th scope="col" class="text-right">{{ t('Impressions') }}</th><th scope="col" class="text-right">{{ t('Click rate') }}</th><th scope="col" class="text-right">{{ t('Position') }}</th></tr>
                </template>
                <tr v-for="row in report.searchTerms.rows" :key="row.query">
                    <td class="font-bold text-ink">{{ row.query }}</td>
                    <td class="text-right tabular-nums">{{ number(row.clicks) }}</td>
                    <td class="text-right tabular-nums">{{ number(row.impressions) }}</td>
                    <td class="text-right tabular-nums">{{ row.ctr }}%</td>
                    <td class="text-right tabular-nums">{{ row.position }}</td>
                </tr>
            </DataTable>
        </section>

        <section v-if="report.engagement.length > 0" class="ui-card grid gap-4 p-5 sm:p-6" aria-labelledby="engagement-heading">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="engagement-heading" class="text-lg font-extrabold text-ink">{{ t('Engagement') }}</h2>
                <p class="text-xs text-muted">{{ t('Time the page was visible, and how far down visitors scrolled.') }}</p>
            </div>
            <DataTable :caption="t('Engagement by page')" :framed="false">
                <template #head>
                    <tr><th scope="col">{{ t('Page') }}</th><th scope="col" class="text-right">{{ t('Pageviews') }}</th><th scope="col" class="text-right">{{ t('Time on page') }}</th><th scope="col" class="text-right">{{ t('Scroll depth') }}</th></tr>
                </template>
                <tr v-for="row in report.engagement" :key="row.path">
                    <td class="max-w-xs truncate"><NuxtLink :to="narrow('path', row.path)" class="text-muted hover:text-ink hover:underline">{{ row.path }}</NuxtLink></td>
                    <td class="text-right tabular-nums">{{ number(row.pageviews) }}</td>
                    <td class="text-right tabular-nums">{{ row.seconds === null ? '—' : duration(row.seconds) }}</td>
                    <td class="text-right tabular-nums">{{ row.scroll === null ? '—' : `${row.scroll}%` }}</td>
                </tr>
            </DataTable>
        </section>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <section v-for="list in report.lists" :key="list.key" class="ui-card p-5" :aria-label="list.title">
                <h2 class="font-extrabold text-ink">{{ list.title }}</h2>
                <ul class="mt-4 grid gap-2.5 text-sm">
                    <li v-for="item in list.items" :key="item.value" class="flex items-center justify-between gap-4">
                        <NuxtLink v-if="list.filter && item.value !== 'Unknown'" :to="narrow(list.filter, item.value)" class="truncate text-muted hover:text-ink hover:underline">{{ item.label }}</NuxtLink>
                        <span v-else class="truncate text-muted">{{ item.label }}</span>
                        <strong class="tabular-nums text-ink">{{ number(item.count) }}</strong>
                    </li>
                    <li v-if="list.items.length === 0" class="text-muted">{{ list.empty }}</li>
                </ul>
            </section>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="ui-card p-5" aria-labelledby="goals-heading">
                <div class="flex items-center justify-between gap-3">
                    <h2 id="goals-heading" class="font-extrabold text-ink">{{ t('Goals') }}</h2>
                    <NuxtLink v-if="goalsUrl" :to="goalsUrl" class="text-xs font-bold text-muted hover:text-ink">{{ t('Manage goals') }}</NuxtLink>
                </div>
                <ul class="mt-4 grid gap-2.5 text-sm">
                    <li v-for="goal in report.goals" :key="goal.name" class="flex items-center justify-between gap-4">
                        <span class="truncate text-muted">{{ goal.name }}</span>
                        <span class="shrink-0 text-right tabular-nums"><span v-if="goal.revenue" class="mr-2 text-xs font-bold text-success">{{ goal.revenue }}</span><strong class="text-ink">{{ number(goal.value) }}</strong></span>
                    </li>
                    <li v-if="report.goals.length === 0" class="text-muted">{{ t('Add a page or event goal to measure conversions.') }}</li>
                </ul>
            </section>
            <LivePanel :recent="report.recent" :url="liveUrl" />
        </div>
    </div>
</template>
