<script setup lang="ts">
import type { ReportPeriod, SitePage } from '~/types/analytics';
import type { Option } from '~/types/ui';

/**
 * A site's deeper reports, one tab at a time: what changed, paths around a page, custom property breakdowns, items
 * sold, attribution, click maps, forms, A/B tests and weekly retention. The tab, period and choices stay in the address.
 */
definePageMeta({ layout: 'app', service: 'analytics' });
type Row = { label: string; value: number };
type Experiment = {
    id: number; key: string; name: string; goal: string | null; status: string; startedAt: string; stoppedAt: string | null;
    variants: Array<{ variant: string; visitors: number; conversions: number; rate: number; lift: number | null; pValue: number | null; significant: boolean }>;
};
type ExplorePage = SitePage & {
    customProperties: string[];
    tab: string;
    period: ReportPeriod | null;
    path: string | null;
    event: string | null;
    property: string | null;
    by: 'channel' | 'campaign';
    goals: Option[];
    // The tab's result; its shape depends on the tab.
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    result: any;
};
const { t, tc, number, date } = useT();
const route = useRoute();
const text = (value: unknown) => (typeof value === 'string' && value !== '' ? value : undefined);
const { data } = await useApi<ExplorePage>(() => `/projects/${route.params.project}/analytics/explore`, () => Object.fromEntries(
    ['site', 'tab', 'days', 'from', 'to', 'compare', 'path', 'event', 'property', 'by'].map((key) => [key, text(route.query[key])]),
));
const project = computed(() => data.value.overview.project);
const site = computed(() => data.value.site);
const base = computed(() => `/api/app/projects/${project.value.id}/analytics/sites/${site.value?.id}`);
const tabs = computed(() => ({
    insights: t('Insights'), paths: t('Paths'), properties: t('Properties'), items: t('Items'), attribution: t('Attribution'),
    clicks: t('Clicks'), forms: t('Forms'), experiments: t('Experiments'), retention: t('Retention'),
}));
const period = data.value.period;
const form = reactive({
    period: { days: period && !period.custom ? String(period.days) : '30', from: period?.custom ? period.start : '', to: period?.custom ? period.end : '', compare: period?.compare ?? 'previous' },
    path: data.value.path ?? '',
    event: data.value.event ?? '',
    property: data.value.property ?? '',
    by: data.value.by as string | null,
});
const result = computed(() => data.value.result);
const eventOptions = computed(() => [{ value: '', label: t('Choose an event') }, ...((data.value.tab === 'properties' ? result.value?.events ?? [] : []) as Row[]).map((row) => ({ value: row.label, label: `${row.label} (${number(row.value)})` }))]);
const propertyOptions = computed(() => [{ value: '', label: t('Choose a property') }, ...data.value.customProperties.map((property) => ({ value: property, label: property }))]);
const byOptions = computed(() => [{ value: 'channel', label: t('Channel') }, { value: 'campaign', label: t('Campaign') }]);
const goalOptions = computed(() => [{ value: '', label: t('Choose a goal') }, ...data.value.goals]);

/** Show the tab with the form's period and choices. */
function apply() {
    const periodQuery = form.period.from && form.period.to ? { from: form.period.from, to: form.period.to } : { days: form.period.days ?? '30' };
    navigateTo({ query: {
        site: site.value ? String(site.value.id) : undefined, tab: data.value.tab, ...periodQuery,
        compare: form.period.compare === 'previous' ? undefined : form.period.compare ?? undefined,
        path: ['paths', 'clicks'].includes(data.value.tab) ? form.path || undefined : undefined,
        event: data.value.tab === 'properties' ? form.event || undefined : undefined,
        property: data.value.tab === 'properties' ? form.property || undefined : undefined,
        by: data.value.tab === 'attribution' && form.by === 'campaign' ? 'campaign' : undefined,
    } });
}
/** This tab around another page. */
const here = (path: string) => ({ query: { site: site.value ? String(site.value.id) : undefined, tab: data.value.tab, ...(data.value.period?.query ?? {}), path } });
const money = (amount: number, currency: string) => `${currency} ${amount.toFixed(2)}`;
const lift = (value: number) => `${value > 0 ? '+' : ''}${value.toFixed(1)}%`;
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Explore')" :description="t('What changed, how people move through the site, what they buy and whether they come back.')">
            <template v-if="data.canManage && site && data.tab === 'experiments'" #actions>
                <UiButton variant="primary" :to="{ query: { ...route.query, dialog: 'new-experiment' } }"><Icon name="plus" class="h-4 w-4" />{{ t('Start an A/B test') }}</UiButton>
            </template>
        </ProjectHeader>
        <EmptyState v-if="!site" icon="view-grid" :title="t('Add a site first')" :description="t('Explore reports belong to a site.')" />
        <template v-else>
            <SitePicker :sites="data.sites" :site="site" />
            <PageTabs :tabs="tabs" :current="data.tab" :label="t('Explore')" :keep="{ site: String(site.id), ...Object.fromEntries(Object.entries(data.period?.query ?? {}).map(([key, value]) => [key, String(value)])) }" />

            <form v-if="!['retention', 'experiments'].includes(data.tab)" class="ui-card grid gap-3 p-4 sm:grid-cols-2 sm:p-5 lg:grid-cols-5 lg:items-end" @submit.prevent="apply">
                <PeriodFields v-model="form.period" :today="null" :compare="data.tab === 'insights'" />
                <InputField v-if="['paths', 'clicks'].includes(data.tab)" v-model="form.path" name="path" :label="t('Page')" placeholder="/pricing" />
                <SelectField v-else-if="data.tab === 'attribution'" v-model="form.by" name="by" :label="t('By')" :options="byOptions" />
                <template v-else-if="data.tab === 'properties'">
                    <SelectField v-model="form.event" name="event" :label="t('Event')" :options="eventOptions" />
                    <SelectField v-model="form.property" name="property" :label="t('Property')" :options="propertyOptions" />
                </template>
                <div class="flex gap-2 sm:col-span-2 lg:col-span-5"><UiButton type="submit" variant="primary">{{ t('Show') }}</UiButton></div>
            </form>

            <section v-if="data.tab === 'insights'" class="ui-card grid gap-4 p-5 sm:p-6" aria-labelledby="insights-heading">
                <div>
                    <h2 id="insights-heading" class="text-lg font-extrabold text-ink">{{ t('What changed') }}</h2>
                    <p class="text-sm text-muted">{{ t('The biggest moves against :comparison: at least half again, or half as much, on ten or more visits or pageviews.', { comparison: data.period?.compare === 'year' ? t('the same period last year') : t('the period before') }) }}</p>
                </div>
                <div v-for="(insight, index) in result" :key="index" class="flex flex-wrap items-center justify-between gap-3 rounded-control border border-line p-3 text-sm">
                    <span class="flex min-w-0 items-center gap-2">
                        <Badge :tone="insight.tone">{{ insight.change === 'New' ? t('New') : insight.change }}</Badge>
                        <span class="text-muted">{{ insight.dimension }}</span>
                        <span class="truncate font-bold text-ink">{{ insight.label }}</span>
                    </span>
                    <span class="tabular-nums text-muted">{{ number(insight.previous) }} → <strong class="text-ink">{{ number(insight.current) }}</strong></span>
                </div>
                <p v-if="result.length === 0" class="text-sm text-muted">{{ t('Nothing changed much. Insights appear once there’s enough traffic to compare.') }}</p>
            </section>

            <section v-else-if="data.tab === 'paths'" class="ui-card grid gap-4 p-5 sm:p-6" aria-labelledby="paths-heading">
                <h2 id="paths-heading" class="text-lg font-extrabold text-ink">{{ data.path ? t('Around :path', { path: data.path }) : t('Choose a page to explore') }}</h2>
                <ul v-if="!data.path" class="grid gap-2 text-sm">
                    <li v-for="row in (result.starts as Row[])" :key="row.label" class="flex justify-between gap-4"><NuxtLink :to="here(row.label)" class="truncate text-primary hover:underline">{{ row.label }}</NuxtLink><strong class="tabular-nums text-ink">{{ number(row.value) }}</strong></li>
                </ul>
                <template v-else>
                    <p class="text-sm text-muted">{{ tc(':count view in this period. Click a page to follow the path from there.|:count views in this period. Click a page to follow the path from there.', result.views, { count: number(result.views) }) }}</p>
                    <div class="grid gap-6 md:grid-cols-2">
                        <div v-for="column in [{ key: 'previous', title: t('Came from') }, { key: 'next', title: t('Went to') }]" :key="column.key">
                            <h3 class="text-sm font-extrabold text-ink">{{ column.title }}</h3>
                            <ul class="mt-2 grid gap-2 text-sm">
                                <li v-for="row in (result[column.key] as Row[])" :key="row.label" class="flex justify-between gap-4">
                                    <span v-if="row.label.startsWith('(')" class="text-muted">{{ row.label === '(exit)' ? t('Left the site') : t('Arrived here') }}</span>
                                    <NuxtLink v-else :to="here(row.label)" class="truncate text-primary hover:underline">{{ row.label }}</NuxtLink>
                                    <strong class="tabular-nums text-ink">{{ number(row.value) }}</strong>
                                </li>
                                <li v-if="result[column.key].length === 0" class="text-muted">{{ t('No views of this page in the period.') }}</li>
                            </ul>
                        </div>
                    </div>
                </template>
            </section>

            <section v-else-if="data.tab === 'properties'" class="ui-card grid gap-4 p-5 sm:p-6" aria-labelledby="properties-heading">
                <h2 id="properties-heading" class="text-lg font-extrabold text-ink">{{ t('Custom property breakdown') }}</h2>
                <p v-if="data.customProperties.length === 0" class="text-sm text-muted">
                    {{ t('List the property names to keep in the site’s settings, then send them with custom events, such as buildpusher.track(\'signup\', { plan: \'pro\' }).') }}
                    <NuxtLink :to="{ query: { ...route.query, dialog: 'site-settings' } }" class="ui-link">{{ t('Site settings') }}</NuxtLink>
                </p>
                <p v-else-if="result.values.length === 0" class="text-sm text-muted">{{ t('Choose an event and a property to see its values.') }}</p>
                <DataTable v-else :caption="t('Values of :property on :event', { property: data.property ?? '', event: data.event ?? '' })" :framed="false">
                    <template #head><tr><th scope="col">{{ data.property }}</th><th scope="col" class="text-right">{{ t('Events') }}</th><th scope="col" class="text-right">{{ t('Visitors') }}</th><th scope="col" class="text-right">{{ t('Revenue') }}</th></tr></template>
                    <tr v-for="row in result.values" :key="row.label">
                        <td class="font-bold text-ink">{{ row.label }}</td><td class="text-right tabular-nums">{{ number(row.events) }}</td><td class="text-right tabular-nums">{{ number(row.visitors) }}</td>
                        <td class="text-right tabular-nums">{{ row.revenue > 0 ? row.revenue.toFixed(2) : '—' }}</td>
                    </tr>
                </DataTable>
            </section>

            <section v-else-if="data.tab === 'items'" class="ui-card grid gap-4 p-5 sm:p-6" aria-labelledby="items-heading">
                <div>
                    <h2 id="items-heading" class="text-lg font-extrabold text-ink">{{ t('Items sold') }}</h2>
                    <p class="text-sm text-muted">{{ t('From events that list their items, such as buildpusher.track(\'purchase\', { revenue: 30, currency: \'USD\', items: [{ id: \'sku-1\', name: \'Pro plan\', price: 30, quantity: 1 }] }).') }}</p>
                </div>
                <p v-if="result.length === 0" class="text-sm text-muted">{{ t('No items in this period.') }}</p>
                <DataTable v-else :caption="t('Items sold')" :framed="false">
                    <template #head><tr><th scope="col">{{ t('Item') }}</th><th scope="col">{{ t('Category') }}</th><th scope="col" class="text-right">{{ t('Quantity') }}</th><th scope="col" class="text-right">{{ t('Orders') }}</th><th scope="col" class="text-right">{{ t('Revenue') }}</th></tr></template>
                    <tr v-for="row in result" :key="`${row.item}-${row.currency}`">
                        <td class="font-bold text-ink">{{ row.item }}</td><td class="text-muted">{{ row.category ?? '—' }}</td><td class="text-right tabular-nums">{{ number(row.quantity) }}</td>
                        <td class="text-right tabular-nums">{{ number(row.orders) }}</td><td class="text-right tabular-nums">{{ money(row.revenue, row.currency) }}</td>
                    </tr>
                </DataTable>
            </section>

            <section v-else-if="data.tab === 'attribution'" class="ui-card grid gap-4 p-5 sm:p-6" aria-labelledby="attribution-heading">
                <div>
                    <h2 id="attribution-heading" class="text-lg font-extrabold text-ink">{{ t('Who gets the credit') }}</h2>
                    <p class="text-sm text-muted">{{ t('Last touch credits where the converting visit came from; first touch credits where the visitor first came from. First touch needs data-retention on the snippet to look past a single visit.') }}</p>
                </div>
                <p v-if="result.length === 0" class="text-sm text-muted">{{ t('No goal completions in this period.') }}</p>
                <DataTable v-else :caption="t('Attribution')" :framed="false">
                    <template #head>
                        <tr><th scope="col">{{ data.by === 'campaign' ? t('Campaign') : t('Channel') }}</th><th scope="col" class="text-right">{{ t('First touch') }}</th><th scope="col" class="text-right">{{ t('Last touch') }}</th><th scope="col" class="text-right">{{ t('Revenue (first)') }}</th><th scope="col" class="text-right">{{ t('Revenue (last)') }}</th></tr>
                    </template>
                    <tr v-for="row in result" :key="row.label">
                        <td class="font-bold text-ink">{{ row.label }}</td><td class="text-right tabular-nums">{{ number(row.first) }}</td><td class="text-right tabular-nums">{{ number(row.last) }}</td>
                        <td class="text-right tabular-nums">{{ row.first_revenue ?? '—' }}</td><td class="text-right tabular-nums">{{ row.last_revenue ?? '—' }}</td>
                    </tr>
                </DataTable>
            </section>

            <section v-else-if="data.tab === 'clicks'" class="ui-card grid gap-4 p-5 sm:p-6" aria-labelledby="clicks-heading">
                <div>
                    <h2 id="clicks-heading" class="text-lg font-extrabold text-ink">{{ data.path ? t('Clicks on :path', { path: data.path }) : t('Pages with the most clicks') }}</h2>
                    <p class="text-sm text-muted">{{ t('Add data-clicks to the snippet to record where people click. Only a short description of the element and its position are kept.') }}</p>
                </div>
                <ul v-if="!data.path" class="grid gap-2 text-sm">
                    <li v-for="row in (result.pages as Row[])" :key="row.label" class="flex justify-between gap-4"><NuxtLink :to="here(row.label)" class="truncate text-primary hover:underline">{{ row.label }}</NuxtLink><strong class="tabular-nums text-ink">{{ number(row.value) }}</strong></li>
                    <li v-if="result.pages.length === 0" class="text-muted">{{ t('No clicks recorded in this period.') }}</li>
                </ul>
                <div v-else class="grid gap-6 lg:grid-cols-2">
                    <figure class="grid gap-2">
                        <svg viewBox="0 0 100 160" class="w-full rounded-control border border-line bg-surface-muted" role="img" :aria-label="t('Where people clicked on the page, top to bottom')">
                            <circle v-for="(point, index) in result.points" :key="index" :cx="point.x" :cy="point.y * 1.6" r="1.2" fill="var(--product-analytics)" fill-opacity="0.35" />
                        </svg>
                        <figcaption class="text-xs text-muted">{{ tc(':count click, placed by its position on the page (top to bottom).|:count clicks, placed by their position on the page (top to bottom).', result.points.length, { count: number(result.points.length) }) }}</figcaption>
                    </figure>
                    <div>
                        <h3 class="text-sm font-extrabold text-ink">{{ t('Most clicked') }}</h3>
                        <ul class="mt-2 grid gap-2 text-sm">
                            <li v-for="row in (result.targets as Row[])" :key="row.label" class="flex justify-between gap-4"><span class="truncate font-mono text-xs text-muted">{{ row.label }}</span><strong class="tabular-nums text-ink">{{ number(row.value) }}</strong></li>
                            <li v-if="result.targets.length === 0" class="text-muted">{{ t('No clicks on this page in the period.') }}</li>
                        </ul>
                    </div>
                </div>
            </section>

            <section v-else-if="data.tab === 'forms'" class="ui-card grid gap-4 p-5 sm:p-6" aria-labelledby="forms-heading">
                <div>
                    <h2 id="forms-heading" class="text-lg font-extrabold text-ink">{{ t('Forms') }}</h2>
                    <p class="text-sm text-muted">{{ t('Add data-forms to the snippet to see who starts each form, who sends it, and which field people leave on. What people type is never recorded.') }}</p>
                </div>
                <div v-for="item in result" :key="item.form" class="grid gap-2 border-t border-line pt-4">
                    <p class="flex flex-wrap items-baseline justify-between gap-2">
                        <span class="font-mono font-bold text-ink">{{ item.form }}</span>
                        <span class="text-sm text-muted">{{ t(':started started · :submitted sent · :rate% completed', { started: number(item.started), submitted: number(item.submitted), rate: item.started > 0 ? Math.round(item.submitted / item.started * 100) : 0 }) }}</span>
                    </p>
                    <DataTable :caption="t('Fields of :form', { form: item.form })" :framed="false">
                        <template #head><tr><th scope="col">{{ t('Field') }}</th><th scope="col" class="text-right">{{ t('Reached') }}</th><th scope="col" class="text-right">{{ t('Left here') }}</th></tr></template>
                        <tr v-for="field in item.fields" :key="field.field">
                            <td class="font-mono text-xs">{{ field.field }}</td><td class="text-right tabular-nums">{{ number(field.reached) }}</td>
                            <td :class="['text-right tabular-nums', field.left > 0 && 'font-bold text-danger']">{{ number(field.left) }}</td>
                        </tr>
                    </DataTable>
                </div>
                <p v-if="result.length === 0" class="text-sm text-muted">{{ t('No form activity in this period.') }}</p>
            </section>

            <template v-else-if="data.tab === 'experiments'">
                <EmptyState v-if="result.length === 0" icon="chart" :title="t('No experiments yet')" :description="t('Start one to compare two versions of a page against a goal.')" />
                <section v-for="experiment in (result as Experiment[])" :key="experiment.id" class="ui-card grid gap-3 p-5 sm:p-6" :aria-labelledby="`experiment-${experiment.id}`">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 :id="`experiment-${experiment.id}`" class="text-lg font-extrabold text-ink">{{ experiment.name }} <span class="font-mono text-sm font-normal text-muted">{{ experiment.key }}</span></h2>
                            <p class="text-sm text-muted">
                                {{ t('Goal: :goal', { goal: experiment.goal ?? t('none') }) }} ·
                                {{ experiment.status === 'running' ? t('running since :date', { date: date(experiment.startedAt) }) : t('stopped :date', { date: experiment.stoppedAt ? date(experiment.stoppedAt) : '' }) }}
                            </p>
                        </div>
                        <div v-if="data.canManage" class="flex gap-1">
                            <ApiForm v-if="experiment.status === 'running'" :action="`${base}/experiments/${experiment.id}`" method="PUT"><SubmitButton variant="secondary" size="sm">{{ t('Stop') }}</SubmitButton></ApiForm>
                            <DeleteDialog :id="`delete-experiment-${experiment.id}`" :title="t('Delete :experiment?', { experiment: experiment.name })" :action="`${base}/experiments/${experiment.id}`">
                                <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Delete') }}</UiButton></template>
                            </DeleteDialog>
                        </div>
                    </div>
                    <DataTable :caption="t('Results of :name', { name: experiment.name })" :framed="false">
                        <template #head>
                            <tr><th scope="col">{{ t('Variant') }}</th><th scope="col" class="text-right">{{ t('Visitors') }}</th><th scope="col" class="text-right">{{ t('Conversions') }}</th><th scope="col" class="text-right">{{ t('Rate') }}</th><th scope="col" class="text-right">{{ t('Lift') }}</th><th scope="col" class="text-right">{{ t('Confidence') }}</th></tr>
                        </template>
                        <tr v-for="(variant, index) in experiment.variants" :key="variant.variant">
                            <td class="font-bold text-ink">{{ variant.variant }}<span v-if="index === 0" class="text-xs font-normal text-muted"> ({{ t('control') }})</span></td>
                            <td class="text-right tabular-nums">{{ number(variant.visitors) }}</td>
                            <td class="text-right tabular-nums">{{ number(variant.conversions) }}</td>
                            <td class="text-right tabular-nums">{{ variant.rate }}%</td>
                            <td class="text-right tabular-nums">{{ variant.lift === null ? '—' : lift(variant.lift) }}</td>
                            <td class="text-right">
                                <template v-if="variant.pValue === null">—</template>
                                <Badge v-else-if="variant.significant" tone="success">{{ t('Significant') }}</Badge>
                                <span v-else class="text-xs text-muted">{{ t('Not yet (p = :p)', { p: variant.pValue }) }}</span>
                            </td>
                        </tr>
                    </DataTable>
                </section>
                <FormDialog v-if="data.canManage" id="new-experiment" :title="t('Start an A/B test')" :description="t('On the page, call buildpusher.variant(\'key\', [\'control\', \'b\']) and show whichever variant it returns. Each visitor keeps the same variant for their visit.')" :action="`${base}/experiments`" :submit="t('Start')">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <InputField id="experiment-name" name="name" :label="t('Name')" maxlength="120" required placeholder="Bolder headline" autofocus />
                        <InputField id="experiment-key" name="key" :label="t('Key')" maxlength="60" required placeholder="headline" />
                        <InputField id="experiment-variants" name="variants" :label="t('Variants (control first)')" maxlength="400" required placeholder="control, bold" />
                        <SelectField id="experiment-goal" name="goal_id" :label="t('Goal')" :options="goalOptions" />
                    </div>
                </FormDialog>
            </template>

            <section v-else class="ui-card grid gap-4 p-5 sm:p-6" aria-labelledby="retention-heading">
                <div>
                    <h2 id="retention-heading" class="text-lg font-extrabold text-ink">{{ t('Weekly retention') }}</h2>
                    <p class="text-sm text-muted">{{ t('Visitors grouped by the week they first came, and the share who came back each week after.') }}</p>
                </div>
                <Alert v-if="!result.tracked" tone="info">{{ t('Add data-retention to the snippet to recognise returning browsers. It keeps a random ID in the visitor’s browser, so ask for consent where the law requires it.') }}</Alert>
                <DataTable v-else :caption="t('Weekly retention')" :framed="false">
                    <template #head>
                        <tr><th scope="col">{{ t('First week') }}</th><th scope="col" class="text-right">{{ t('Visitors') }}</th><th v-for="after in 7" :key="after" scope="col" class="text-right">{{ t('Week :n', { n: after }) }}</th></tr>
                    </template>
                    <tr v-for="cohort in result.cohorts" :key="cohort.week">
                        <td class="whitespace-nowrap">{{ date(cohort.week) }}</td>
                        <td class="text-right tabular-nums">{{ number(cohort.size) }}</td>
                        <td v-for="(share, index) in cohort.returned" :key="index" class="text-right tabular-nums">{{ share === null ? '' : `${share}%` }}</td>
                    </tr>
                </DataTable>
            </section>
        </template>
        <SiteSettingsDialog v-if="data.canManage && site" :project-id="project.id" :site-id="site.id" />
    </div>
</template>
