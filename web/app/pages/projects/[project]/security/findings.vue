<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';
import type { FindingRow } from '~/types/security';
import type { Option } from '~/types/ui';

/** Every finding, most serious first, filtered by status, check and severity (kept in the address). */
definePageMeta({ layout: 'app', service: 'security' });
type FindingsPage = {
    overview: ProjectOverview;
    filters: { status: 'open' | 'ignored' | 'resolved'; source: string | null; severity: string | null };
    findings: FindingRow[];
    page: number;
    lastPage: number;
    sources: Option[];
    severities: Option[];
    canManage: boolean;
};
const { t } = useT();
const route = useRoute();
const text = (value: unknown) => (typeof value === 'string' && value !== '' ? value : undefined);
const { data } = await useApi<FindingsPage>(() => `/projects/${route.params.project}/security/findings`, () => ({
    status: text(route.query.status), source: text(route.query.source), severity: text(route.query.severity), page: text(route.query.page),
}));
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/security`);
const filters = reactive({ status: data.value.filters.status as string | null, source: data.value.filters.source ?? '', severity: data.value.filters.severity ?? '' });
const statusOptions = computed(() => [{ value: 'open', label: t('Open') }, { value: 'ignored', label: t('Ignored') }, { value: 'resolved', label: t('Resolved') }]);
const sourceOptions = computed(() => [{ value: '', label: t('All checks') }, ...data.value.sources]);
const severityOptions = computed(() => [{ value: '', label: t('Any severity') }, ...data.value.severities]);

/** Show the findings with the chosen filters, from the first page. */
function apply() {
    navigateTo({ query: { status: filters.status === 'open' ? undefined : filters.status ?? undefined, source: filters.source || undefined, severity: filters.severity || undefined } });
}
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Findings')" :description="t('Everything Security has found, most serious first. Findings resolve themselves when a scan no longer sees them.')" />
        <form class="ui-card grid gap-3 p-4 sm:grid-cols-[repeat(3,minmax(0,1fr))_auto] sm:items-end" role="search" @submit.prevent="apply">
            <SelectField v-model="filters.status" name="status" :label="t('Status')" :options="statusOptions" @change="apply" />
            <SelectField v-model="filters.source" name="source" :label="t('Check')" :options="sourceOptions" @change="apply" />
            <SelectField v-model="filters.severity" name="severity" :label="t('Severity')" :options="severityOptions" @change="apply" />
            <UiButton type="submit">{{ t('Filter') }}</UiButton>
        </form>

        <EmptyState
            v-if="data.findings.length === 0"
            icon="shield-check"
            :title="t('No findings here')"
            :description="data.filters.status === 'open' ? t('Nothing open matches. Checks keep running in the background.') : t('Nothing matches these filters.')"
        />
        <template v-else>
            <section class="ui-card overflow-hidden">
                <ul class="divide-y divide-line" :aria-label="t('Findings')">
                    <FindingItem v-for="finding in data.findings" :key="finding.id" :finding="finding" :base="base" :can-manage="data.canManage" />
                </ul>
            </section>
            <Pager :page="data.page" :last-page="data.lastPage" />
        </template>
    </div>
</template>
