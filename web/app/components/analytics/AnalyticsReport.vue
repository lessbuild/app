<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router';
import type { AnalyticsReport, ReportFilters, ReportList } from '~/types/analytics';

/**
 * A site's report (the Acme theme's web analytics overview): headline numbers, the chart against the comparison period
 * (with notes and releases beneath it), the ranked lists grouped into tabbed cards (each row narrows the report to it),
 * goals, page speed, Google search terms, engagement and the live panel. Shared by the Analytics page and the shared and
 * view-only reports; `link` builds the address of the same report with other filters.
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
const router = useRouter();
const hourly = computed(() => props.report.granularity === 'hour');
const chartLabel = computed(() => (hourly.value ? t('Pageviews per hour') : t('Pageviews per day')));
const compare = ref(true);
const chartSeries = computed(() => [
    { name: t('This period'), values: props.report.series.map((point) => point.value) },
    ...(compare.value && props.report.previousSeries ? [{ name: t('Comparison period'), values: props.report.previousSeries.map((point) => point.value) }] : []),
]);
// The ranked lists, grouped into cards with a tab each; lists the report doesn't have are left out.
const groups = computed(() => [
    ['pages', 'entryPages', 'exitPages', 'contentGroups'],
    ['channels', 'sources', 'campaigns', 'terms', 'contents'],
    ['countries', 'regions', 'cities'],
    ['devices', 'browsers', 'operatingSystems', 'screenSizes', 'browserVersions', 'osVersions'],
    ['outboundLinks', 'fileDownloads', 'notFound', 'searches', 'emptySearches'],
].map((keys) => keys.map((key) => props.report.lists.find((list) => list.key === key)).filter((list) => list !== undefined)).filter((group) => group.length > 0));
const chosen = reactive<Record<number, string>>({});
const current = (index: number) => groups.value[index]!.find((list) => list.key === chosen[index]) ?? groups.value[index]![0]!;
const items = (list: ReportList) => list.items.map((item) => ({ label: item.label, value: item.count, to: list.filter && item.value !== 'Unknown' ? router.resolve(narrow(list.filter, item.value)).fullPath : undefined }));
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

const vitalNames = computed(() => ({
    lcp: [t('Largest Contentful Paint'), t('How long the main content takes to appear')],
    inp: [t('Interaction to Next Paint'), t('How quickly the page responds to taps and clicks')],
    cls: [t('Cumulative Layout Shift'), t('How much the page jumps around while loading')],
    ttfb: [t('Time to First Byte'), t('How long the server takes to answer')],
}));
const ratings = computed(() => ({ good: { label: t('Good'), tone: 'green' as const }, needs_improvement: { label: t('Needs improvement'), tone: 'amber' as const }, poor: { label: t('Poor'), tone: 'red' as const } }));
/** Format a Core Web Vital: layout shift as a score, times in milliseconds or seconds. */
const vital = (metric: string, value: number) => (metric === 'cls' ? value.toFixed(2) : value >= 1000 ? `${(value / 1000).toFixed(2)} s` : `${number(Math.round(value))} ms`);
/** Format seconds on a page as 1m 05s or 42s. */
const duration = (seconds: number) => (seconds >= 60 ? `${Math.floor(seconds / 60)}m ${String(seconds % 60).padStart(2, '0')}s` : `${seconds}s`);
</script>

<template>
    <div class="space-y-6">
        <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3 xl:grid-cols-6">
            <AcmeStat
                v-for="metric in report.metrics"
                :key="metric.label"
                :label="metric.label"
                :value="metric.value"
                :delta="metric.change && metric.change !== 'New' ? metric.change.replace(/\.0%$/, '%') : undefined"
                :down="metric.change?.startsWith('-')"
                :period="metric.change && metric.change !== 'New' ? (report.period.compare === 'year' ? t('vs last year') : t('vs previous period')) : changeText(metric.change)"
            />
        </div>

        <AcmeCard :title="chartLabel" :description="`${hourly ? date(report.period.start) : `${date(report.period.start)} – ${date(report.period.end)}`} · ${report.timezone}`">
            <template v-if="report.previousSeries" #action>
                <label class="flex items-center gap-2 text-sm text-muted"><input v-model="compare" type="checkbox" class="size-4 accent-[var(--ui-primary)]">{{ t('Compare') }}</label>
            </template>
            <AcmeLineChart v-if="report.hasData" :labels="report.series.map((point) => point.date)" :series="chartSeries" :label="chartLabel" :format="(value: number) => number(value)" :height="260" />
            <p v-else class="text-sm text-muted">{{ t('No pageviews in this period yet.') }}</p>
            <div v-if="annotations.length > 0 || releases.length > 0 || notesAction" class="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-4">
                <span v-for="note in annotations" :key="note.id" class="inline-flex items-center gap-1.5 rounded-full bg-black/[.04] px-2.5 py-1 text-xs dark:bg-white/[.06]">
                    <AcmeIcon name="pin" :size="12" class="text-muted" /><b class="font-medium text-ink">{{ date(note.date) }}</b> {{ note.text }}
                    <ApiForm v-if="notesAction" :action="`${notesAction}/${note.id}`" method="DELETE" class="inline-flex"><button type="submit" class="text-muted hover:text-ink" :aria-label="t('Remove')"><AcmeIcon name="close" :size="12" /></button></ApiForm>
                </span>
                <span v-for="release in releases" :key="`release-${release.id}`" class="inline-flex items-center gap-1.5 rounded-full bg-accent/[.08] px-2.5 py-1 text-xs">
                    <AcmeIcon name="rocket" :size="12" class="text-accent" /><b class="font-mono font-medium text-ink">{{ release.version }}</b> {{ release.environment }} · {{ dateTime(release.deployedAt) }}
                </span>
                <AcmeBtn v-if="notesAction" size="sm" variant="ghost" icon="plus" :to="{ query: { ...$route.query, dialog: 'new-note' } }">{{ t('Add a note') }}</AcmeBtn>
            </div>
        </AcmeCard>

        <div class="grid gap-6 xl:grid-cols-2">
            <AcmeCard v-for="(group, index) in groups" :key="group[0]!.key" :padded="false">
                <div class="px-5 pt-4 sm:px-6">
                    <nav class="flex gap-5 overflow-x-auto overflow-y-hidden overscroll-x-contain border-b border-line [scrollbar-width:none]" :aria-label="current(index).title">
                        <button
                            v-for="list in group"
                            :key="list.key"
                            type="button"
                            :class="['shrink-0 whitespace-nowrap border-b-2 pb-3 text-sm font-medium', list.key === current(index).key ? 'border-accent text-ink' : 'border-transparent text-muted hover:text-ink']"
                            :aria-pressed="list.key === current(index).key"
                            @click="chosen[index] = list.key"
                        >
                            {{ list.title }}
                        </button>
                    </nav>
                </div>
                <div class="p-5 sm:p-6">
                    <AcmeBarList v-if="current(index).items.length > 0" :items="items(current(index))" :label="current(index).title" :value-label="['pages', 'entryPages', 'exitPages', 'contentGroups'].includes(current(index).key) ? t('Pageviews') : t('Visitors')" :format="(value: number) => number(value)" />
                    <p v-else class="text-sm text-muted">{{ current(index).empty ?? t('Nothing here in this period.') }}</p>
                </div>
            </AcmeCard>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <AcmeCard :title="t('Goals')" :link="goalsUrl ? { label: t('Manage goals'), to: goalsUrl } : undefined" :padded="false">
                <DataTable v-if="report.goals.length > 0" :caption="t('Goals')" :framed="false">
                    <template #head><tr><th scope="col">{{ t('Goal') }}</th><th scope="col" class="text-right">{{ t('Conversions') }}</th><th scope="col" class="text-right">{{ t('Revenue') }}</th></tr></template>
                    <tr v-for="goal in report.goals" :key="goal.name">
                        <td class="font-medium text-ink">{{ goal.name }}</td>
                        <td class="text-right tabular-nums">{{ number(goal.value) }}</td>
                        <td class="text-right tabular-nums">{{ goal.revenue ?? '—' }}</td>
                    </tr>
                </DataTable>
                <p v-else class="px-5 pb-5 text-sm text-muted sm:px-6">{{ t('Add a page or event goal to measure conversions.') }}</p>
            </AcmeCard>
            <LivePanel :recent="report.recent" :url="liveUrl" />
        </div>

        <AcmeCard :title="t('Page speed')" :description="report.vitals.samples > 0 ? tc('75th percentile of :count page load by real visitors|75th percentile of :count page loads by real visitors', report.vitals.samples, { count: number(report.vitals.samples) }) : t('Core Web Vitals from real visitors')">
            <p v-if="report.vitals.samples === 0" class="text-sm text-muted">{{ t('Add data-vitals to the snippet to measure how fast pages load for real visitors, and see whether a release made them slower.') }}</p>
            <template v-else>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div v-for="(names, metric) in vitalNames" :key="metric" class="rounded-xl border border-line p-4">
                        <p class="flex items-center justify-between gap-2 text-xs text-muted">
                            <span class="uppercase">{{ metric }}</span>
                            <AcmeBadge v-if="report.vitals.metrics[metric]?.rating" :tone="ratings[report.vitals.metrics[metric]!.rating!].tone">{{ ratings[report.vitals.metrics[metric]!.rating!].label }}</AcmeBadge>
                        </p>
                        <p class="mt-2 text-2xl font-semibold tabular-nums text-ink">{{ report.vitals.metrics[metric]?.value == null ? '—' : vital(metric, report.vitals.metrics[metric]!.value!) }}</p>
                        <p class="mt-1 text-xs text-muted" :title="names[0]">{{ names[1] }}</p>
                    </div>
                </div>
                <template v-if="report.vitals.slowPages.length > 0">
                    <h3 class="mt-6 text-sm font-medium text-ink">{{ t('Slowest pages to load') }}</h3>
                    <ul class="mt-2 divide-y divide-line text-sm">
                        <li v-for="page in report.vitals.slowPages" :key="page.path" class="flex items-center gap-3 py-2.5">
                            <NuxtLink :to="narrow('path', page.path)" class="flex-1 truncate font-mono text-xs text-ink hover:underline">{{ page.path }}</NuxtLink>
                            <span class="text-xs text-muted">{{ tc(':count load|:count loads', page.samples, { count: page.samples }) }}</span>
                            <b :class="['w-16 text-right tabular-nums', page.lcp > 2500 ? 'text-amber-600 dark:text-amber-400' : 'text-ink']">{{ vital('lcp', page.lcp) }}</b>
                        </li>
                    </ul>
                </template>
            </template>
        </AcmeCard>

        <div v-if="report.searchTerms || report.engagement.length > 0" class="grid gap-6 xl:grid-cols-2">
            <AcmeCard v-if="report.searchTerms" :title="t('Google search terms')" :description="t('From Google Search Console (:property). Google’s figures lag by about two days.', { property: report.searchTerms.property })" :padded="false">
                <AcmeAlert v-if="report.searchTerms.error" tone="warning" class="mx-5 mb-5 sm:mx-6">{{ report.searchTerms.error }}</AcmeAlert>
                <p v-else-if="report.searchTerms.rows.length === 0" class="px-5 pb-5 text-sm text-muted sm:px-6">{{ t('No searches led here in this period.') }}</p>
                <DataTable v-else :caption="t('Google search terms')" :framed="false">
                    <template #head>
                        <tr><th scope="col">{{ t('Search term') }}</th><th scope="col" class="text-right">{{ t('Clicks') }}</th><th scope="col" class="text-right">{{ t('Click rate') }}</th><th scope="col" class="text-right">{{ t('Position') }}</th></tr>
                    </template>
                    <tr v-for="row in report.searchTerms.rows" :key="row.query">
                        <td class="text-ink">{{ row.query }}<span class="block text-xs text-muted">{{ tc(':count impression|:count impressions', row.impressions, { count: number(row.impressions) }) }}</span></td>
                        <td class="text-right tabular-nums">{{ number(row.clicks) }}</td>
                        <td class="text-right tabular-nums">{{ row.ctr }}%</td>
                        <td class="text-right tabular-nums">{{ row.position }}</td>
                    </tr>
                </DataTable>
            </AcmeCard>
            <AcmeCard v-if="report.engagement.length > 0" :title="t('Engagement')" :description="t('Time the page was visible, and how far down visitors scrolled.')" :padded="false">
                <ul class="divide-y divide-line border-t border-line text-sm">
                    <li v-for="row in report.engagement" :key="row.path" class="grid grid-cols-[1fr_5rem_7rem] items-center gap-3 px-5 py-3 sm:px-6">
                        <NuxtLink :to="narrow('path', row.path)" class="min-w-0 truncate font-mono text-xs text-ink hover:underline">{{ row.path }}<span class="block font-sans text-muted">{{ tc(':count view|:count views', row.pageviews, { count: number(row.pageviews) }) }}</span></NuxtLink>
                        <span class="text-right tabular-nums">{{ row.seconds === null ? '—' : duration(row.seconds) }}</span>
                        <span class="flex items-center gap-2">
                            <AcmeProgress v-if="row.scroll !== null" :value="row.scroll" :label="t(':page scroll depth', { page: row.path })" size="sm" class="flex-1" />
                            <span class="w-9 text-right text-xs tabular-nums">{{ row.scroll === null ? '—' : `${row.scroll}%` }}</span>
                        </span>
                    </li>
                </ul>
            </AcmeCard>
        </div>
    </div>
</template>
