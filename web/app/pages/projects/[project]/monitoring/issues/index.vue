<script setup lang="ts">
import type { IssueRow } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';
import type { Option } from '~/types/ui';

/**
 * Errors grouped by what caused them (the Acme theme's issues page): open issues, new today, occurrences over the last
 * day and people affected, then each issue with its 12-hour trend, filtered by status, text and who's on them (kept in
 * the address).
 */
definePageMeta({ layout: 'app', service: 'monitoring' });
type IssuesPage = {
    overview: ProjectOverview;
    issues: Array<IssueRow & { exceptionType: string | null; users: number; firstSeenAt: string; environment: string | null; eventsLastHour: number; eventsPreviousHour: number; isNew: boolean; spiking: boolean; trend: number[] }>;
    stats: { open: number; newToday: number; events: number; eventsBefore: number; hourly: number[]; users: number };
    page: number;
    lastPage: number;
    filters: { q?: string; status: string; ownership: string; severity?: string; environment?: string; sort: string };
    statuses: Option[];
    canUpdate: boolean;
};
const { t, number } = useT();
const route = useRoute();
const text = (value: unknown) => (typeof value === 'string' && value !== '' ? value : undefined);
const { data } = await useApi<IssuesPage>(() => `/projects/${route.params.project}/monitoring/issues`, () => ({
    q: text(route.query.q), status: text(route.query.status), ownership: text(route.query.ownership), severity: text(route.query.severity), environment: text(route.query.environment), sort: text(route.query.sort), page: text(route.query.page),
}));
const project = computed(() => data.value.overview.project);
const filters = reactive({
    q: data.value.filters.q ?? '',
    status: data.value.filters.status as string | null,
    ownership: data.value.filters.ownership as string | null,
    severity: data.value.filters.severity ?? 'all',
    environment: data.value.filters.environment ?? 'all',
    sort: data.value.filters.sort ?? 'impact',
});
const statusOptions = computed(() => [{ value: 'all', label: t('All') }, ...data.value.statuses]);
const ownershipOptions = computed(() => [{ value: 'any', label: t('Anyone') }, { value: 'mine', label: t('To me') }, { value: 'unassigned', label: t('No one') }]);
const severityOptions = computed(() => [
    { value: 'all', label: t('All severities') },
    { value: 'critical', label: t('Critical') }, { value: 'error', label: t('Error') },
    { value: 'warning', label: t('Warning') }, { value: 'info', label: t('Info') }, { value: 'debug', label: t('Debug') },
]);
const environmentOptions = computed(() => [
    { value: 'all', label: t('All environments') },
    ...data.value.overview.environments.map((environment) => ({ value: environment.id, label: environment.name })),
]);
const sortOptions = computed(() => [{ value: 'impact', label: t('Highest impact') }, { value: 'newest', label: t('Most recent') }]);
const issueEnvironment = (issue: IssuesPage['issues'][number]) => filters.environment === 'all'
    ? issue.environment : environmentOptions.value.find((environment) => environment.value === filters.environment)?.label ?? issue.environment;

const status = computed({ get: () => filters.status ?? 'open', set: (value: string | number) => { filters.status = String(value); apply(); } });
const stats = computed(() => data.value.stats);
const change = computed(() => (stats.value.eventsBefore === 0 ? undefined : `${stats.value.events >= stats.value.eventsBefore ? '+' : '−'}${Math.abs(Math.round(((stats.value.events - stats.value.eventsBefore) / stats.value.eventsBefore) * 100))}%`));
const issueTitle = (issue: IssuesPage['issues'][number]) => issue.exceptionType ? `${issue.exceptionType}: ${issue.title}` : issue.title;

/** Show the issues with the chosen filters, from the first page. */
function apply() {
    navigateTo({ query: {
        q: filters.q || undefined,
        status: filters.status === 'open' ? undefined : filters.status ?? undefined,
        ownership: filters.ownership === 'any' ? undefined : filters.ownership ?? undefined,
        severity: filters.severity === 'all' ? undefined : filters.severity,
        environment: filters.environment === 'all' ? undefined : filters.environment,
        sort: filters.sort === 'impact' ? undefined : filters.sort,
    } });
}
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Monitoring')" :description="t('Errors from your servers and browsers, grouped so each bug shows up once. A new occurrence reopens a resolved issue.')">
            <template #actions>
                <AcmeBtn icon="config" :to="`/projects/${project.id}/monitoring/setup`">{{ t('Send errors') }}</AcmeBtn>
            </template>
        </ProjectHeader>
        <div class="space-y-6">
            <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
                <AcmeStat :label="t('Open issues')" :value="number(stats.open)" :period="t('matching filters')" icon="flame" tone="red" tinted />
                <AcmeStat :label="t('New today')" :value="number(stats.newToday)" :period="t('matching filters')" icon="sparkle" tone="violet" />
                <AcmeStat :label="t('Events, 24 h')" :value="number(stats.events)" :delta="change" :down="stats.events < stats.eventsBefore" :period="t('vs the day before')" icon="trending" :spark="stats.hourly" />
                <AcmeStat :label="t('People affected')" :value="number(stats.users)" :period="t('by matching open issues')" icon="people" />
            </div>
            <p class="-mt-4 text-xs text-muted">{{ t('Counts follow search, severity, environment and assignment filters. Status only changes the issue list. People affected is the lifetime total for each issue.') }}</p>

            <AcmeAlert v-if="data.canUpdate" tone="info" :title="t('Get notified about exceptions')">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                    <NuxtLink :to="{ path: `/projects/${project.id}/monitoring/rules/create`, query: { metric: 'critical_issue_count', environment_id: filters.environment === 'all' ? undefined : filters.environment } }" class="font-medium underline">{{ t('Alert on new critical issues') }}</NuxtLink>
                    <NuxtLink :to="{ path: `/projects/${project.id}/monitoring/rules/create`, query: { metric: 'exception_increase', environment_id: filters.environment === 'all' ? undefined : filters.environment } }" class="font-medium underline">{{ t('Alert on exception spikes') }}</NuxtLink>
                    <span class="text-xs opacity-80">{{ t('Choose destinations on the rule page after saving.') }}</span>
                </div>
            </AcmeAlert>

            <AcmeCard :padded="false">
                <form class="flex flex-wrap items-end gap-3 px-5 pt-5 sm:px-6" role="search" @submit.prevent="apply">
                    <AcmeSegmented v-model="status" :options="statusOptions" :label="t('Status')" size="sm" />
                    <SelectField v-model="filters.severity" name="severity" :label="t('Severity')" :options="severityOptions" hide-label class="w-40" @update:model-value="apply" />
                    <SelectField v-model="filters.environment" name="environment" :label="t('Environment')" :options="environmentOptions" hide-label class="w-40" @update:model-value="apply" />
                    <SelectField v-model="filters.ownership" name="ownership" :label="t('Assigned')" :options="ownershipOptions" hide-label class="w-36" @update:model-value="apply" />
                    <SelectField v-model="filters.sort" name="sort" :label="t('Sort')" :options="sortOptions" hide-label class="w-40" @update:model-value="apply" />
                    <AcmeSearchInput v-model="filters.q" :label="t('Search')" :placeholder="t('Search errors or files')" class="ml-auto w-full sm:w-64" />
                </form>
                <div class="px-5 pt-3 sm:px-6"><SavedViews page="monitoring.issues" :keys="['environment', 'ownership', 'q', 'severity', 'sort', 'status']" :project="project.id" /></div>
                <ul v-if="data.issues.length > 0" class="mt-4 divide-y divide-line border-t border-line" :aria-label="t('Issues')">
                    <li v-for="issue in data.issues" :key="issue.id">
                        <NuxtLink :to="`/projects/${project.id}/monitoring/issues/${issue.id}`" class="grid gap-3 px-5 py-4 hover:bg-black/[.02] sm:px-6 lg:grid-cols-[1fr_7rem_5rem_5rem] lg:items-center dark:hover:bg-white/[.03]">
                            <span class="min-w-0">
                                <span class="flex flex-wrap items-center gap-2">
                                    <AcmeBadge :tone="issue.severity === 'critical' || issue.severity === 'error' ? 'red' : issue.severity === 'warning' ? 'amber' : 'gray'">{{ issue.severityLabel }}</AcmeBadge>
                                    <AcmeBadge v-if="issue.isNew" tone="violet">{{ t('New') }}</AcmeBadge>
                                    <AcmeBadge v-if="issue.spiking" tone="red" dot :title="t('At least 5 exceptions in the last hour and twice the previous hour')">{{ t('Spike') }}</AcmeBadge>
                                    <AcmeBadge v-if="issue.status !== 'open'" :tone="acmeTone(issue.statusTone)">{{ issue.statusLabel }}</AcmeBadge>
                                </span>
                                <b :title="issueTitle(issue)" class="mt-1 block min-w-0 break-words [overflow-wrap:anywhere] font-mono text-sm font-medium text-ink line-clamp-2">{{ issueTitle(issue) }}</b>
                                <span class="mt-1 block truncate text-xs text-muted">
                                    {{ issue.location ?? t('Unknown location') }} · <Rich :text="t('first :first, last :last')"><template #first><RelativeTime :at="issue.firstSeenAt" /></template><template #last><RelativeTime v-if="issue.lastSeenAt" :at="issue.lastSeenAt" /></template></Rich>
                                    <template v-if="issueEnvironment(issue)"> · {{ issueEnvironment(issue) }}</template>
                                    <template v-if="issue.assignee"> · {{ issue.assignee }}</template>
                                </span>
                            </span>
                            <AcmeSparkline :values="issue.trend" :label="t(':issue, last 12 hours', { issue: issueTitle(issue) })" area />
                            <span class="text-sm tabular-nums"><b class="font-medium text-ink">{{ number(issue.eventsLastHour) }}</b><span class="block text-xs text-muted">{{ t('events, last hour') }}</span><span class="block text-[0.6875rem] text-muted">{{ t(':count total', { count: number(issue.occurrences) }) }}</span></span>
                            <span class="text-sm tabular-nums"><b class="font-medium text-ink">{{ number(issue.users) }}</b><span class="block text-xs text-muted">{{ t('people') }}</span></span>
                        </NuxtLink>
                    </li>
                </ul>
                <div v-else class="p-5 sm:p-6">
                    <AcmeEmptyState icon="checkCircle" :title="t('No issues here')" :description="t('Exceptions your apps send become issues. Connect an app on the Setup page to start collecting them.')">
                        <AcmeBtn :to="`/projects/${project.id}/monitoring/setup`">{{ t('Open setup') }}</AcmeBtn>
                    </AcmeEmptyState>
                </div>
            </AcmeCard>
            <Pager :page="data.page" :last-page="data.lastPage" />
        </div>
    </div>
</template>
