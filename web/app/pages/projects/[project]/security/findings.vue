<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';
import type { FindingRow } from '~/types/security';
import type { Option } from '~/types/ui';

/**
 * Every finding, most serious first (the Acme theme's findings page): status tabs with counts, filtered by check and
 * severity (kept in the address).
 */
definePageMeta({ layout: 'app', service: 'security' });
type FindingsPage = {
    overview: ProjectOverview;
    filters: { status: 'open' | 'ignored' | 'resolved'; source: string | null; severity: string | null };
    findings: FindingRow[];
    counts: Record<string, number>;
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
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Security')" :description="t('Everything Security has found, most serious first. Findings resolve themselves when a scan no longer sees them.')" />
        <div class="space-y-6">
            <AcmeCard :padded="false">
                <div class="px-5 pt-4 sm:px-6">
                    <nav class="flex gap-6 border-b border-line" :aria-label="t('Status')">
                        <NuxtLink
                            v-for="option in statusOptions"
                            :key="option.value"
                            :to="{ query: { ...route.query, status: option.value === 'open' ? undefined : option.value, page: undefined } }"
                            :class="['-mb-px flex items-center gap-2 border-b-2 pb-3 text-sm font-medium', data.filters.status === option.value ? 'border-accent text-ink' : 'border-transparent text-muted hover:text-ink']"
                            :aria-current="data.filters.status === option.value ? 'page' : undefined"
                        >
                            {{ option.label }}<span class="rounded-full bg-black/[.06] px-1.5 text-xs tabular-nums dark:bg-white/10">{{ data.counts[option.value] ?? 0 }}</span>
                        </NuxtLink>
                    </nav>
                </div>
                <form class="flex flex-wrap gap-3 px-5 pt-4 sm:px-6" role="search" @submit.prevent="apply">
                    <SelectField v-model="filters.source" name="source" :label="t('Check')" :options="sourceOptions" hide-label class="w-56" @change="apply" />
                    <SelectField v-model="filters.severity" name="severity" :label="t('Severity')" :options="severityOptions" hide-label class="w-44" @change="apply" />
                </form>
                <ul v-if="data.findings.length > 0" class="mt-4 divide-y divide-line border-t border-line" :aria-label="t('Findings')">
                    <FindingItem v-for="finding in data.findings" :key="finding.id" :finding="finding" :base="base" :can-manage="data.canManage" />
                </ul>
                <div v-else class="p-5 sm:p-6">
                    <AcmeEmptyState icon="shield" :title="t('No findings here')" :description="data.filters.status === 'open' ? t('Nothing open matches. Checks keep running in the background.') : t('Nothing matches these filters.')" />
                </div>
            </AcmeCard>
            <Pager :page="data.page" :last-page="data.lastPage" />
        </div>
    </div>
</template>
