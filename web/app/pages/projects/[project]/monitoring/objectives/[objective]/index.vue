<script setup lang="ts">
import type { ObjectiveSummary } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';
import type { Tone } from '~/types/ui';

/** One objective: compliance, the error budget left, the requests behind them and how fast the budget is burning. */
definePageMeta({ layout: 'app', service: 'monitoring' });
type ObjectivePage = {
    overview: ProjectOverview;
    objective: ObjectiveSummary;
    report: { good: number; bad: number; observed: number; unknown: number; from: string; until: string };
    burnRate: { status: string; label: string; message: string; short: number | null; long: number | null } | null;
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
const burn = (rate: number | null) => (rate === null ? '—' : `${new Intl.NumberFormat(locale.value, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(rate)}×`);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="objective.name" :description="`${objective.indicator} · ${t(':target% target', { target: objective.target })} · ${objective.window} · ${objective.scope}`">
            <template #actions>
                <a v-if="data.canExport" :href="`${base}/export`" class="ui-btn ui-btn-secondary ui-btn-sm" download><Icon name="arrow-down" class="h-4 w-4" />{{ t('Download CSV') }}</a>
                <template v-if="data.canManage">
                    <AcmeBtn :to="`/projects/${project.id}/monitoring/objectives/${objective.id}/edit`" size="sm">{{ t('Edit') }}</AcmeBtn>
                    <DeleteDialog
                        id="archive-objective"
                        :title="t('Archive :objective?', { objective: objective.name })"
                        :description="t('Burn-rate rules that use it stop working. Its history is kept.')"
                        :action="base"
                        :submit-label="t('Archive objective')"
                    >
                        <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Archive') }}</AcmeBtn></template>
                    </DeleteDialog>
                </template>
            </template>
        </ProjectHeader>

        <div class="flex items-center gap-3"><ObjectiveBadge :status="objective.status" :enabled="objective.enabled" /><span class="text-sm text-muted">{{ objective.environment }}</span></div>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard :label="t('Compliance')" :value="objective.compliance === null ? '—' : `${objective.compliance}%`" />
            <StatCard :label="t('Error budget remaining')" :value="budget" />
            <StatCard :label="t('Good requests')" :value="number(data.report.good)" />
            <StatCard :label="t('Bad requests')" :value="number(data.report.bad)" />
        </div>
        <p class="text-xs text-muted">
            {{ t(':observed requests measured between :from and :until; :unknown couldn’t be judged.', { observed: number(data.report.observed), from: dateTime(data.report.from), until: dateTime(data.report.until), unknown: number(data.report.unknown) }) }}
        </p>

        <section class="ui-card grid gap-2 p-5 text-sm" aria-labelledby="burn-rate">
            <h2 id="burn-rate" class="flex items-center gap-2 font-bold text-ink">
                {{ t('Burn-rate analysis') }}
                <AcmeBadge v-if="data.burnRate" :tone="burnTone">{{ data.burnRate.label }}</AcmeBadge>
            </h2>
            <template v-if="data.burnRate">
                <p class="text-muted">{{ data.burnRate.message }}</p>
                <p class="text-xs text-muted">{{ t('Last hour: :short · last 6 hours: :long', { short: burn(data.burnRate.short), long: burn(data.burnRate.long) }) }}</p>
            </template>
            <p v-else class="text-muted">{{ t('Burn-rate analysis comes with Monitoring Pro and above.') }}</p>
        </section>
        <p v-if="!data.canExport" class="text-xs text-muted">{{ t('CSV reports come with Monitoring Team and Scale.') }}</p>
    </div>
</template>
