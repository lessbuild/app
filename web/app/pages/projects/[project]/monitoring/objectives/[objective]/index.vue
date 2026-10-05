<script setup lang="ts">
import type { ObjectiveSummary } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';
import type { Tone } from '~/types/ui';

/**
 * One objective (the Acme theme's SLO page): compliance, the error budget left and how it's moved over two weeks, the
 * requests behind them and how fast the budget is burning.
 */
definePageMeta({ layout: 'app', service: 'monitoring' });
type ObjectivePage = {
    overview: ProjectOverview;
    objective: ObjectiveSummary;
    report: { good: number; bad: number; observed: number; unknown: number; from: string; until: string };
    burnRate: { status: string; label: string; message: string; short: number | null; long: number | null } | null;
    history: Array<{ date: string; remaining: number | null }>;
    allowed: number | null;
    canExport: boolean;
    canManage: boolean;
};
const { t, number, dateTime, locale } = useT();
const route = useRoute();
const { data } = await useApi<ObjectivePage>(() => `/projects/${route.params.project}/monitoring/objectives/${route.params.objective}`);
const project = computed(() => data.value.overview.project);
const objective = computed(() => data.value.objective);
const base = computed(() => `/api/app/projects/${project.value.id}/monitoring/objectives/${objective.value.id}`);
const budget = computed(() => {
    const remaining = objective.value.budgetRemaining;
    return remaining === null ? '—' : remaining <= 0 ? t('Exhausted') : `${remaining}%`;
});
const burnTone = computed<Tone>(() => ({ critical: 'danger', warning: 'warning', healthy: 'success' } as Record<string, Tone>)[data.value.burnRate?.status ?? ''] ?? 'neutral');
const chart = computed(() => ({
    labels: data.value.history.map((point) => new Date(`${point.date}T12:00:00Z`).toLocaleDateString(locale.value, { month: 'short', day: 'numeric' })),
    values: data.value.history.map((point) => point.remaining ?? 0),
}));
const measured = computed(() => data.value.history.some((point) => point.remaining !== null));
const burn = (rate: number | null) => (rate === null ? '—' : `${new Intl.NumberFormat(locale.value, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(rate)}×`);
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="objective.name" :description="`${objective.indicator} · ${t(':target% target', { target: objective.target })} · ${objective.window} · ${objective.scope}`">
            <template #actions>
                <a v-if="data.canExport" :href="`${base}/export`" class="ui-btn ui-btn-secondary" download>{{ t('Download CSV') }}</a>
                <template v-if="data.canManage">
                    <AcmeBtn icon="edit" :to="`/projects/${project.id}/monitoring/objectives/${objective.id}/edit`">{{ t('Edit') }}</AcmeBtn>
                    <DeleteDialog
                        id="archive-objective"
                        :title="t('Archive :objective?', { objective: objective.name })"
                        :description="t('Burn-rate rules that use it stop working. Its history is kept.')"
                        :action="base"
                        :submit-label="t('Archive objective')"
                    >
                        <template #trigger="{ open }"><AcmeBtn variant="ghost" icon="archive" @click="open">{{ t('Archive') }}</AcmeBtn></template>
                    </DeleteDialog>
                </template>
            </template>
        </ProjectHeader>

        <div class="space-y-6">
            <AcmeAlert v-if="objective.budgetRemaining !== null && objective.budgetRemaining <= 0" tone="danger" :title="t('The error budget is used up')">
                {{ t('Hold back risky deploys and spend time on reliability until it recovers.') }}
            </AcmeAlert>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <AcmeStat :label="t('Now')" :value="objective.compliance === null ? '—' : `${objective.compliance}%`" :tone="objective.compliance !== null && objective.compliance < Number(objective.target) ? 'red' : 'green'" icon="target" :period="objective.environment" />
                <AcmeStat :label="t('Budget left')" :value="budget" icon="piggy" />
                <AcmeStat :label="t('Good requests')" :value="number(data.report.good)" icon="checkCircle" />
                <AcmeStat :label="t('Bad requests')" :value="data.allowed === null ? number(data.report.bad) : t(':bad of :allowed allowed', { bad: number(data.report.bad), allowed: number(data.allowed) })" icon="circleX" />
            </div>
            <p class="text-xs text-muted">
                {{ t(':observed requests measured between :from and :until; :unknown couldn’t be judged.', { observed: number(data.report.observed), from: dateTime(data.report.from), until: dateTime(data.report.until), unknown: number(data.report.unknown) }) }}
            </p>

            <AcmeCard :title="t('Error budget left')" :description="t('At the end of each of the last 14 days, over the :window window', { window: objective.window })">
                <AcmeLineChart v-if="measured" :labels="chart.labels" :series="[{ name: t('Budget left'), values: chart.values }]" :label="t('Error budget left')" :format="(value: number) => `${Math.round(value)}%`" />
                <p v-else class="text-sm text-muted">{{ t('No requests measured yet.') }}</p>
            </AcmeCard>

            <AcmeCard :title="t('Burn rate')" :description="t('How fast the budget is being used: 1× uses it exactly by the end of the window.')">
                <template v-if="data.burnRate" #action><AcmeBadge :tone="acmeTone(burnTone)" dot>{{ data.burnRate.label }}</AcmeBadge></template>
                <template v-if="data.burnRate">
                    <p class="text-sm text-muted">{{ data.burnRate.message }}</p>
                    <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-xl border border-line p-4"><dt class="text-xs text-muted">{{ t('Last hour') }}</dt><dd class="mt-1 text-2xl font-semibold tabular-nums text-ink">{{ burn(data.burnRate.short) }}</dd></div>
                        <div class="rounded-xl border border-line p-4"><dt class="text-xs text-muted">{{ t('Last 6 hours') }}</dt><dd class="mt-1 text-2xl font-semibold tabular-nums text-ink">{{ burn(data.burnRate.long) }}</dd></div>
                    </dl>
                </template>
                <p v-else class="text-sm text-muted">{{ t('Burn-rate analysis comes with Monitoring Pro and above.') }}</p>
            </AcmeCard>
            <p v-if="!data.canExport" class="text-xs text-muted">{{ t('CSV reports come with Monitoring Team and Scale.') }}</p>
        </div>
    </div>
</template>
