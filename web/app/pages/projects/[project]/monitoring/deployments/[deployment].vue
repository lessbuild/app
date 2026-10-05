<script setup lang="ts">
import type { ReleaseMetrics } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';
import type { Option } from '~/types/ui';

/** One deployment: what went live, and the service's traffic and errors before and after it, side by side. */
definePageMeta({ layout: 'app', service: 'monitoring' });
type DeploymentPage = {
    overview: ProjectOverview;
    deployment: {
        id: number;
        releaseId: number;
        version: string;
        service: string;
        environmentId: string;
        environment: string;
        deployedAt: string;
        actor: string | null;
        commit: string | null;
        note: string | null;
        buildId: number | null;
    };
    filters: { window: string };
    comparison: { before: ReleaseMetrics | null; after: ReleaseMetrics | null; seconds: number };
    windows: Option[];
};
const { t, number, dateTime, locale } = useT();
const route = useRoute();
const text = (value: unknown) => (typeof value === 'string' && value !== '' ? value : undefined);
const { data } = await useApi<DeploymentPage>(() => `/projects/${route.params.project}/monitoring/deployments/${route.params.deployment}`, () => ({ window: text(route.query.window) }));
const project = computed(() => data.value.overview.project);
const deployment = computed(() => data.value.deployment);
const windowMinutes = ref<string | null>(String(data.value.filters.window));
const decimal = (value: number, digits: number) => new Intl.NumberFormat(locale.value, { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(value);
type Row = { label: string; before: number | null; after: number | null; format: (value: number) => string; lowerIsBetter: boolean };
const rows = computed<Row[]>(() => {
    const { before, after } = data.value.comparison;
    if (!before || !after) {
        return [];
    }
    return [
        { label: t('Requests'), before: before.requests, after: after.requests, format: (value) => number(value), lowerIsBetter: false },
        { label: t('Error rate'), before: before.errorRate, after: after.errorRate, format: (value) => `${decimal(value, 2)}%`, lowerIsBetter: true },
        { label: t('Average response'), before: before.averageDuration, after: after.averageDuration, format: (value) => `${decimal(value, 1)} ms`, lowerIsBetter: true },
        { label: t('Exceptions'), before: before.exceptions, after: after.exceptions, format: (value) => number(value), lowerIsBetter: true },
    ];
});
/** The change from before to after as a percentage, with whether it's worse for a measure where lower is better. */
function change(row: Row): { text: string; worse: boolean } | null {
    if (row.before === null || row.after === null || row.before === 0) {
        return null;
    }
    const percent = ((row.after - row.before) / row.before) * 100;
    return { text: `${percent > 0 ? '+' : ''}${decimal(percent, 1)}%`, worse: row.lowerIsBetter && percent > 0 };
}
const eventsLink = computed(() => ({ path: `/projects/${project.value.id}/monitoring/events`, query: { release: String(deployment.value.releaseId), environment: deployment.value.environmentId, has_trace: 'yes', range: 'all' } }));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader
            :overview="data.overview"
            :title="t('Deployment of :version', { version: deployment.version })"
            :description="`${deployment.environment} · ${dateTime(deployment.deployedAt)} · ${deployment.actor ?? t('Pipeline')}`"
        />
        <div class="ui-card grid gap-2 p-5 text-sm">
            <p>
                <span class="text-muted">{{ t('Release') }}:</span>
                <NuxtLink :to="`/projects/${project.id}/monitoring/releases/${deployment.releaseId}`" class="text-primary hover:underline">{{ deployment.version }}</NuxtLink> · {{ deployment.service }}
            </p>
            <p v-if="deployment.commit"><span class="text-muted">{{ t('Commit') }}:</span> <code>{{ deployment.commit }}</code></p>
            <p v-if="deployment.note" class="whitespace-pre-wrap">{{ deployment.note }}</p>
            <p class="flex flex-wrap gap-3">
                <NuxtLink :to="eventsLink" class="text-primary hover:underline">{{ t('Requests and traces from this release') }}</NuxtLink>
                <NuxtLink v-if="deployment.buildId" :to="`/projects/${project.id}/deploy/builds/${deployment.buildId}`" class="text-primary hover:underline">{{ t('Deploy #:id', { id: deployment.buildId }) }}</NuxtLink>
            </p>
        </div>
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="navigateTo({ query: { window: windowMinutes ?? undefined } })">
            <SelectField v-model="windowMinutes" name="window" :label="t('Compare')" :options="data.windows" />
            <AcmeBtn type="submit">{{ t('Compare') }}</AcmeBtn>
        </form>
        <EmptyState v-if="rows.length === 0" icon="clock" :title="t('Too soon to compare')" :description="t('Come back once some time has passed since the deployment.')" />
        <template v-else>
            <DataTable :caption="t('Before and after the deployment')">
                <template #head>
                    <tr><th scope="col">{{ t('Measure') }}</th><th scope="col" class="text-right">{{ t('Before') }}</th><th scope="col" class="text-right">{{ t('After') }}</th><th scope="col" class="text-right">{{ t('Change') }}</th></tr>
                </template>
                <tr v-for="row in rows" :key="row.label">
                    <th scope="row" class="font-semibold">{{ row.label }}</th>
                    <td class="text-right tabular-nums">{{ row.before === null ? '—' : row.format(row.before) }}</td>
                    <td class="text-right tabular-nums">{{ row.after === null ? '—' : row.format(row.after) }}</td>
                    <td class="text-right tabular-nums" :class="change(row)?.worse && 'font-bold text-danger'">{{ change(row)?.text ?? '—' }}</td>
                </tr>
            </DataTable>
            <p class="text-xs text-muted">{{ t('Both sides cover :minutes minutes of this service’s telemetry in :environment. A difference is a lead, not proof of cause.', { minutes: Math.floor(data.comparison.seconds / 60), environment: deployment.environment }) }}</p>
        </template>
    </div>
</template>
