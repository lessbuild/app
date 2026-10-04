<script setup lang="ts">
import type { IssueRow } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';
import type { Option } from '~/types/ui';

/** Errors grouped by what caused them, filtered by text, status and who's on them (kept in the address). */
definePageMeta({ layout: 'app', service: 'monitoring' });
type IssuesPage = {
    overview: ProjectOverview;
    issues: IssueRow[];
    page: number;
    lastPage: number;
    filters: { q?: string; status: string; ownership: string; environment?: string };
    statuses: Option[];
};
const { t, tc, number } = useT();
const route = useRoute();
const text = (value: unknown) => (typeof value === 'string' && value !== '' ? value : undefined);
const { data } = await useApi<IssuesPage>(() => `/projects/${route.params.project}/monitoring/issues`, () => ({
    q: text(route.query.q), status: text(route.query.status), ownership: text(route.query.ownership), environment: text(route.query.environment), page: text(route.query.page),
}));
const project = computed(() => data.value.overview.project);
const filters = reactive({ q: data.value.filters.q ?? '', status: data.value.filters.status as string | null, ownership: data.value.filters.ownership as string | null });
const statusOptions = computed(() => [{ value: 'all', label: t('All') }, ...data.value.statuses]);
const ownershipOptions = computed(() => [{ value: 'any', label: t('Anyone') }, { value: 'mine', label: t('To me') }, { value: 'unassigned', label: t('No one') }]);

/** Show the issues with the chosen filters, from the first page. */
function apply() {
    navigateTo({ query: { q: filters.q || undefined, status: filters.status ?? undefined, ownership: filters.ownership === 'any' ? undefined : filters.ownership ?? undefined } });
}
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Issues')" :description="t('Errors grouped by what caused them. A new occurrence reopens a resolved issue.')" />
        <form class="ui-card grid gap-3 p-4 sm:grid-cols-[minmax(0,1fr)_12rem_12rem_auto] sm:items-end" role="search" @submit.prevent="apply">
            <InputField v-model="filters.q" name="q" type="search" :label="t('Search')" :placeholder="t('Title or location')" maxlength="100" />
            <SelectField v-model="filters.status" name="status" :label="t('Status')" :options="statusOptions" />
            <SelectField v-model="filters.ownership" name="ownership" :label="t('Assigned')" :options="ownershipOptions" />
            <UiButton type="submit">{{ t('Filter') }}</UiButton>
        </form>
        <SavedViews page="monitoring.issues" :keys="['environment', 'ownership', 'q', 'status']" :project="project.id" />

        <EmptyState v-if="data.issues.length === 0" icon="check-circle" :title="t('No issues here')" :description="t('Exceptions your apps send become issues. Connect an app on the Setup page to start collecting them.')">
            <UiButton :to="`/projects/${project.id}/monitoring/setup`">{{ t('Open setup') }}</UiButton>
        </EmptyState>
        <template v-else>
            <section class="ui-card overflow-hidden">
                <ul class="divide-y divide-line" :aria-label="t('Issues')">
                    <li v-for="issue in data.issues" :key="issue.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <div class="min-w-0">
                            <NuxtLink :to="`/projects/${project.id}/monitoring/issues/${issue.id}`" class="font-extrabold text-ink hover:underline">{{ issue.title }}</NuxtLink>
                            <p class="mt-0.5 break-all text-xs text-muted">
                                {{ issue.location ?? t('Unknown location') }} · {{ tc(':count occurrence|:count occurrences', issue.occurrences, { count: number(issue.occurrences) }) }}
                                <template v-if="issue.lastSeenAt"> · <Rich :text="t('last :time')"><template #time><RelativeTime :at="issue.lastSeenAt" /></template></Rich></template>
                                <template v-if="issue.assignee"> · {{ issue.assignee }}</template>
                            </p>
                        </div>
                        <span class="flex gap-2">
                            <Badge :tone="issue.severity === 'critical' ? 'danger' : 'neutral'">{{ issue.severityLabel }}</Badge>
                            <Badge :tone="issue.statusTone">{{ issue.statusLabel }}</Badge>
                        </span>
                    </li>
                </ul>
            </section>
            <Pager :page="data.page" :last-page="data.lastPage" />
        </template>
    </div>
</template>
