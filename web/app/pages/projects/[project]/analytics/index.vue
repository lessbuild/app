<script setup lang="ts">
import type { OverviewPage } from '~/types/analytics';

/**
 * A site's report (the Acme theme's web analytics page): choose the site and the period up top, with filters a click
 * away (all kept in the address, so a report can be saved or shared), then the numbers, chart and lists. People who
 * manage Analytics can add notes to the chart and export CSV.
 */
definePageMeta({ layout: 'app', service: 'analytics' });
const FILTERS = ['path', 'source', 'campaign', 'device', 'browser', 'os', 'channel', 'country', 'region', 'city', 'screen', 'term', 'content', 'group'];
const { t, dateTime } = useT();
const route = useRoute();
const text = (value: unknown) => (typeof value === 'string' && value !== '' ? value : undefined);
const { data } = await useApi<OverviewPage>(() => `/projects/${route.params.project}/analytics`, () => Object.fromEntries(
    ['site', 'days', 'from', 'to', 'compare', ...FILTERS].map((key) => [key, text(route.query[key])]),
));
const project = computed(() => data.value.overview.project);
const site = computed(() => data.value.site);
const sitesBase = computed(() => `/api/app/projects/${project.value.id}/analytics/sites/${site.value?.id}`);
const period = computed(() => data.value.report?.period);
const form = reactive({
    period: { days: period.value && !period.value.custom ? String(period.value.days) : '30', from: period.value?.custom ? period.value.start : '', to: period.value?.custom ? period.value.end : '', compare: period.value?.compare ?? 'previous' },
    path: data.value.filters.path ?? '',
    source: data.value.filters.source ?? '',
    device: data.value.filters.device ?? '',
    browser: data.value.filters.browser ?? '',
    os: data.value.filters.os ?? '',
});
const devices = computed(() => [{ value: '', label: t('All devices') }, { value: 'Desktop', label: t('Desktop') }, { value: 'Mobile', label: t('Mobile') }, { value: 'Tablet', label: t('Tablet') }]);
const filterOpen = ref(false);
const presets = computed(() => [{ value: '1', label: t('Today') }, { value: '7', label: t('7 days') }, { value: '30', label: t('30 days') }, { value: '365', label: t('12 months') }]);
const preset = computed({
    get: () => (form.period.from ? '' : String(form.period.days ?? '30')),
    set: (value: string | number) => { form.period = { ...form.period, days: String(value), from: '', to: '' }; apply(); },
});
const activeFilters = computed(() => FILTERS.filter((key) => data.value.filters[key]).map((key) => ({ key, value: data.value.filters[key] as string })));
const filtered = computed(() => FILTERS.some((key) => data.value.filters[key]));
/** The report's period as query parameters (custom dates win over the preset). */
const periodQuery = computed(() => ({
    ...(form.period.from && form.period.to ? { from: form.period.from, to: form.period.to } : { days: form.period.days ?? '30' }),
    compare: form.period.compare === 'previous' ? undefined : form.period.compare ?? undefined,
}));
/** The filters other than the form's own, which list links set (country, campaign and the rest). */
const hiddenFilters = computed(() => Object.fromEntries(['campaign', 'channel', 'country', 'region', 'city', 'screen', 'term', 'content', 'group'].map((key) => [key, data.value.filters[key] ?? undefined])));

/** Show the report with the form's period and filters. */
function apply() {
    navigateTo({ query: {
        site: site.value ? String(site.value.id) : undefined, ...periodQuery.value, ...hiddenFilters.value,
        path: form.path || undefined, source: form.source || undefined, device: form.device || undefined, browser: form.browser || undefined, os: form.os || undefined,
    } });
}
/** The same report narrowed by other filters. */
const link = (filters: Record<string, string>) => ({ path: route.path, query: { site: site.value ? String(site.value.id) : undefined, ...(period.value?.query ?? {}), ...filters } });
const clearLink = computed(() => ({ query: { site: site.value ? String(site.value.id) : undefined, ...Object.fromEntries(Object.entries(period.value?.query ?? {}).map(([key, value]) => [key, String(value)])) } }));
const liveUrl = computed(() => `/projects/${project.value.id}/analytics?${new URLSearchParams({ site: String(site.value?.id ?? ''), live: '1', ...Object.fromEntries(Object.entries(data.value.filters).filter((entry): entry is [string, string] => typeof entry[1] === 'string')) })}`);
// Exports cover a preset period (custom dates fall back to the last 30 days) with the report's filters.
const exportFields = computed(() => ({ days: period.value && !period.value.custom ? String(period.value.days) : '30', ...Object.fromEntries(Object.entries(data.value.filters).filter((entry): entry is [string, string] => typeof entry[1] === 'string')) }));
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Web analytics')" :description="site ? t(':site · cookieless visitor estimates, visits and goals: no banners, no personal data', { site: site.name }) : t('Cookieless visitor estimates, visits and goals: no banners, no personal data.')">
            <template v-if="site" #actions>
                <ApiForm :action="`${sitesBase}/exports`">
                    <input v-for="(value, key) in exportFields" :key="key" type="hidden" :name="key" :value="value">
                    <SubmitButton variant="secondary">{{ t('Export CSV') }}</SubmitButton>
                </ApiForm>
                <AcmeBtn icon="share" :to="`/projects/${project.id}/analytics/sites/${site.id}?dialog=share-report`">{{ t('Share') }}</AcmeBtn>
            </template>
        </ProjectHeader>

        <EmptyState v-if="!site" icon="view-grid" :title="t('Add the website you want to understand')" :description="t('Create a site, add one small script, and your first pageview shows up here as soon as it’s processed.')">
            <template #action><AcmeBtn v-if="data.canManage" variant="primary" icon="plus" :to="{ query: { ...route.query, dialog: 'add-site' } }">{{ t('Add a site') }}</AcmeBtn></template>
        </EmptyState>
        <div v-else class="space-y-6">
            <div class="flex flex-wrap items-center gap-3">
                <SitePicker :sites="data.sites" :site="site" class="w-56" />
                <AcmeSegmented v-model="preset" :options="presets" :label="t('Period')" />
                <AcmeBtn icon="filter" :aria-expanded="filterOpen" @click="filterOpen = !filterOpen">{{ t('Filter') }}</AcmeBtn>
                <NuxtLink v-if="data.report" :to="liveUrl" class="ml-auto flex items-center gap-2 rounded-full border border-line bg-surface px-3 py-1.5 text-sm">
                    <span class="relative flex size-2" aria-hidden="true">
                        <span v-if="data.report.recent.visitorCount > 0" class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75 motion-reduce:hidden" />
                        <span :class="['relative inline-flex size-2 rounded-full', data.report.recent.visitorCount > 0 ? 'bg-emerald-500' : 'bg-zinc-300']" />
                    </span>
                    <b class="tabular-nums text-ink">{{ data.report.recent.visitorCount }}</b> {{ t('right now') }}
                </NuxtLink>
            </div>
            <div v-if="activeFilters.length > 0" class="flex flex-wrap items-center gap-2">
                <AcmeBadge v-for="filter in activeFilters" :key="filter.key" tone="blue">{{ filter.key }}: {{ filter.value }}</AcmeBadge>
                <AcmeBtn size="sm" variant="ghost" :to="clearLink">{{ t('Clear filters') }}</AcmeBtn>
            </div>
            <form v-show="filterOpen" class="grid gap-3 rounded-2xl border border-line bg-surface p-5 shadow-card sm:grid-cols-2 lg:grid-cols-5 lg:items-end" role="search" @submit.prevent="apply">
                <PeriodFields v-model="form.period" :today="data.today" />
                <InputField v-model="form.path" name="path" :label="t('Page')" placeholder="/pricing" />
                <InputField v-model="form.source" name="source" :label="t('Source')" :placeholder="t('newsletter or google.com')" />
                <SelectField v-model="form.device" name="device" :label="t('Device')" :options="devices" />
                <InputField v-model="form.browser" name="browser" :label="t('Browser')" placeholder="Firefox" />
                <InputField v-model="form.os" name="os" :label="t('Operating system')" placeholder="iOS" />
                <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-5">
                    <AcmeBtn type="submit" variant="primary">{{ t('Apply') }}</AcmeBtn>
                    <AcmeBtn v-if="filtered" variant="ghost" :to="clearLink">{{ t('Clear filters') }}</AcmeBtn>
                </div>
                <SavedViews page="analytics.overview" :keys="['site', 'days', 'from', 'to', 'compare', ...FILTERS]" :project="project.id" class="border-t border-line pt-4 sm:col-span-2 lg:col-span-5" />
            </form>

            <AcmeAlert v-if="!site.verified" tone="warning" role="status">
                <Rich :text="t(':site is waiting for verification before it collects.')"><template #site><strong>{{ site.name }}</strong></template></Rich>
                <NuxtLink :to="`/projects/${project.id}/analytics/sites/${site.id}`" class="font-medium underline">{{ t('Finish setup') }}</NuxtLink>
            </AcmeAlert>
            <AcmeAlert v-else-if="!site.collecting" tone="warning" role="status">{{ t('Collection is paused for :site. Existing reports stay available.', { site: site.name }) }}</AcmeAlert>

            <AnalyticsReport
                v-if="data.report"
                :report="data.report"
                :filters="data.filters"
                :link="link"
                :live-url="liveUrl"
                :goals-url="`/projects/${project.id}/analytics/goals?site=${site.id}`"
                :annotations="data.annotations"
                :releases="data.releases"
                :notes-action="data.canManage ? `${sitesBase}/annotations` : null"
            />

            <p class="text-xs text-muted">
                {{ t('Visitors are cookieless daily estimates; visits end after 30 minutes without activity. Raw detail is kept :days days.', { days: data.retentionDays }) }}
                {{ t('Last processed :time.', { time: data.report?.lastProcessedAt ? dateTime(data.report.lastProcessedAt) : t('never') }) }}
            </p>

            <FormDialog v-if="data.canManage" id="new-note" :title="t('Add a note to the chart')" :description="t('Mark a launch, campaign or outage so it’s clear what moved the numbers.')" :action="`${sitesBase}/annotations`" :submit="t('Add note')">
                <InputField id="note-date" name="date" type="date" :label="t('Day')" :model-value="data.today ?? ''" required />
                <InputField id="note-text" name="text" :label="t('Note')" maxlength="200" required autofocus />
            </FormDialog>
        </div>
        <AddSiteDialog v-if="data.canManage && !site" :project-id="project.id" />
    </div>
</template>
