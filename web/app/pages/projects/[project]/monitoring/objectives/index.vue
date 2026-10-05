<script setup lang="ts">
import type { ObjectiveSummary } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/** The project's service level objectives (the Acme theme's SLOs page): how much error budget each has left and how fast it's burning. */
definePageMeta({ layout: 'app', service: 'monitoring' });
const { t, number } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview; objectives: Array<ObjectiveSummary & { burn: { short: number | null; long: number | null } | null }>; canManage: boolean }>(() => `/projects/${route.params.project}/monitoring/objectives`);
const project = computed(() => data.value.overview.project);
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Monitoring')" :description="t('Promises about reliability, such as “checkout works 99.9% of the time”, and how much room is left before you break them.')">
            <template v-if="data.canManage" #actions>
                <AcmeBtn variant="primary" :to="`/projects/${project.id}/monitoring/objectives/create`" icon="plus">{{ t('New SLO') }}</AcmeBtn>
            </template>
        </ProjectHeader>
        <div class="space-y-6">
            <AcmeEmptyState v-if="data.objectives.length === 0" icon="target" :title="t('No objectives yet')" :description="t('For example: 99.9% of requests succeed over 30 days, or 95% finish within 300 ms.')">
                <AcmeBtn v-if="data.canManage" variant="primary" icon="plus" :to="`/projects/${project.id}/monitoring/objectives/create`">{{ t('New SLO') }}</AcmeBtn>
            </AcmeEmptyState>
            <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <NuxtLink v-for="objective in data.objectives" :key="objective.id" :to="`/projects/${project.id}/monitoring/objectives/${objective.id}`" class="rounded-2xl border border-line bg-surface p-5 shadow-card transition hover:border-accent/40">
                    <p class="flex items-start justify-between gap-2"><b class="font-medium text-ink">{{ objective.name }}</b><ObjectiveBadge :status="objective.status" :enabled="objective.enabled" /></p>
                    <p class="mt-1 text-xs text-muted">{{ objective.indicator }} · {{ t(':target% target', { target: objective.target }) }} · {{ objective.window }} · {{ objective.environment }}</p>
                    <div class="mt-4 flex justify-center">
                        <AcmeGauge
                            :value="objective.budgetRemaining === null ? 0 : Math.max(0, Math.min(100, objective.budgetRemaining))"
                            :display="objective.budgetRemaining === null ? '—' : `${Math.round(objective.budgetRemaining)}%`"
                            :label="t(':objective: error budget left', { objective: objective.name })"
                            :caption="t('budget left')"
                            :color="objective.budgetRemaining === null ? '#a1a1aa' : objective.budgetRemaining <= 0 ? '#f43f5e' : objective.budgetRemaining < 25 ? '#f59e0b' : '#10b981'"
                        />
                    </div>
                    <dl class="mt-4 grid grid-cols-2 gap-3 border-t border-line pt-4 text-sm">
                        <div><dt class="text-xs text-muted">{{ t('Now') }}</dt><dd :class="['font-semibold tabular-nums', objective.compliance !== null && objective.compliance < Number(objective.target) ? 'text-rose-600 dark:text-rose-400' : 'text-ink']">{{ objective.compliance === null ? '—' : `${objective.compliance}%` }}</dd></div>
                        <div v-if="objective.burn"><dt class="text-xs text-muted">{{ t('Burn rate, short · long') }}</dt><dd class="tabular-nums text-ink">{{ objective.burn.short === null ? '—' : `${number(objective.burn.short)}×` }} · {{ objective.burn.long === null ? '—' : `${number(objective.burn.long)}×` }}</dd></div>
                    </dl>
                </NuxtLink>
            </div>
            <AcmeCard :title="t('How to read this')">
                <ul class="grid gap-4 text-sm sm:grid-cols-3">
                    <li><b class="font-medium text-ink">{{ t('Error budget') }}</b><p class="mt-1 text-muted">{{ t('The failures you can afford in the window. 99.9% over 30 days allows about 43 minutes of downtime.') }}</p></li>
                    <li><b class="font-medium text-ink">{{ t('Burn rate') }}</b><p class="mt-1 text-muted">{{ t('1× uses the budget exactly by the end of the window. 14× empties a 30-day budget in about two days.') }}</p></li>
                    <li><b class="font-medium text-ink">{{ t('When it’s gone') }}</b><p class="mt-1 text-muted">{{ t('Slow down risky deploys and spend the time on reliability until the budget recovers.') }}</p></li>
                </ul>
            </AcmeCard>
        </div>
    </div>
</template>
