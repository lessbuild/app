<script setup lang="ts">
import type { AuditReport, RunSummary } from '~/types/audit';

/**
 * A run's report: progress while it runs; then the scores against competitors, what to fix first and every journey.
 * A finding or a journey opens in a dialog (?dialog=finding-3, ?dialog=journey-7), so it can be linked to.
 */
definePageMeta({ layout: 'app', service: 'audit' });
const { t, tc, dateTime } = useT();
const route = useRoute();
const { data } = await useApi<{ report: AuditReport }>(() => `/projects/${route.params.project}/audit/runs/${route.params.siteAuditRun}`);
const projectId = computed(() => String(route.params.project));
const base = computed(() => `/projects/${projectId.value}/audit`);
const report = computed(() => data.value.report);
const labels = useFindingLabels();
const running = computed(() => report.value.run.status === 'queued' || report.value.run.status === 'running');
const sites = computed(() => [...new Set(report.value.journeys.map((journey) => journey.siteKey))]);
const goals = computed(() => [...new Set(report.value.journeys.map((journey) => journey.goal))]);
const journeyFor = (goal: string, siteKey: string) => report.value.journeys.find((item) => item.goal === goal && item.siteKey === siteKey);
const starting = ref(false);

/** Run the audit again and go to the new run. */
async function rerun() {
    starting.value = true;
    const result = await send<{ run: RunSummary }>('POST', `${base.value}/${report.value.audit.id}/runs`).catch(() => null);
    if (result) {
        await navigateTo(`${base.value}/runs/${result.run.id}`);
    }
    starting.value = false;
}
useHead({ title: () => t('Audit report') });
</script>

<template>
    <div class="space-y-6">
        <PageHeader
            icon="search"
            :title="t('Report from :date', { date: dateTime(report.run.createdAt) })"
            :description="report.audit.url"
            :breadcrumbs="[{ label: t('Audits'), to: base }, { label: report.audit.name, to: `${base}/${report.audit.id}` }]"
        >
            <template v-if="!running" #actions>
                <UiButton variant="primary" :disabled="starting" @click="rerun"><Icon name="refresh" class="h-4 w-4" />{{ starting ? t('Starting…') : t('Run audit') }}</UiButton>
            </template>
        </PageHeader>

        <RunProgress v-if="running" :project-id="projectId" :report="report" />
        <Alert v-if="report.run.status === 'failed'" tone="danger" role="alert"><strong>{{ t('The audit didn’t finish.') }}</strong> {{ report.run.error }}</Alert>

        <template v-if="report.run.status === 'done'">
            <ScoreTiles :sites="report.sites" />
            <section v-if="report.summary" class="ui-card grid gap-2 p-5 sm:p-6" aria-labelledby="summary-heading">
                <h2 id="summary-heading" class="text-lg font-extrabold text-ink">{{ t('Summary') }}</h2>
                <p class="max-w-3xl text-sm leading-7 text-muted">{{ report.summary }}</p>
            </section>
            <section class="ui-card p-5 sm:p-6" :aria-label="t('Scores by category')"><ScoreComparison :sites="report.sites" /></section>
            <section class="grid gap-3" aria-labelledby="findings-heading">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="findings-heading" class="text-lg font-extrabold text-ink">{{ t('What to improve') }}</h2>
                    <p class="text-xs text-muted">{{ tc(':count finding, most important first|:count findings, most important first', report.findings.length, { count: report.findings.length }) }}</p>
                </div>
                <ol class="grid gap-3 md:grid-cols-2">
                    <li v-for="finding in report.findings" :key="finding.id">
                        <UiDialog :id="`finding-${finding.id}`" :title="finding.title" size="large">
                            <template #trigger="{ open }">
                                <button type="button" class="ui-card ui-card--interactive flex h-full w-full gap-4 p-4 text-left" @click="open">
                                    <img v-if="finding.screenshotUrl" :src="finding.screenshotUrl" alt="" width="128" height="80" loading="lazy" class="hidden h-20 w-32 shrink-0 rounded-control border border-line object-cover object-top sm:block">
                                    <span class="grid min-w-0 content-start gap-2">
                                        <span class="flex flex-wrap gap-1.5">
                                            <Badge :tone="labels.severityTone[finding.severity]">{{ labels.severity[finding.severity] }}</Badge>
                                            <Badge>{{ finding.categoryLabel }}</Badge>
                                            <Badge v-if="finding.mockupUrl" tone="info">{{ t('Mock-up') }}</Badge>
                                        </span>
                                        <span class="text-sm font-extrabold text-ink">{{ finding.title }}</span>
                                        <span class="line-clamp-2 text-xs leading-5 text-muted">{{ finding.recommendation }}</span>
                                    </span>
                                </button>
                            </template>
                            <FindingView :finding="finding" :screen="report.screen" />
                        </UiDialog>
                    </li>
                </ol>
            </section>
        </template>

        <section v-if="report.journeys.length > 0" class="grid gap-3" aria-labelledby="journeys-heading">
            <h2 id="journeys-heading" class="text-lg font-extrabold text-ink">{{ t('Journeys') }}</h2>
            <DataTable :caption="t('Journeys')">
                <template #head>
                    <tr><th scope="col">{{ t('Task') }}</th><th v-for="key in sites" :key="key" scope="col">{{ report.journeys.find((journey) => journey.siteKey === key)?.siteName }}</th></tr>
                </template>
                <tr v-for="goal in goals" :key="goal">
                    <td class="font-semibold text-ink">{{ goal }}</td>
                    <td v-for="key in sites" :key="key">
                        <UiDialog v-if="journeyFor(goal, key)" :id="`journey-${journeyFor(goal, key)!.id}`" :title="`${goal} · ${journeyFor(goal, key)!.siteName}`" size="large">
                            <template #trigger="{ open }">
                                <button type="button" class="ui-link" @click="open">{{ journeyFor(goal, key)!.outcomeLabel }} · {{ tc(':count step|:count steps', journeyFor(goal, key)!.stepsCount, { count: journeyFor(goal, key)!.stepsCount }) }}</button>
                            </template>
                            <JourneyView :journey="journeyFor(goal, key)!" :screen="report.screen" />
                        </UiDialog>
                        <template v-else>—</template>
                    </td>
                </tr>
            </DataTable>
        </section>
    </div>
</template>
