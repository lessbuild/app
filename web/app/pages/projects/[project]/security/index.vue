<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';
import type { FindingRow, SecurityCheck } from '~/types/security';

/**
 * Security's overview (the Acme theme's security overview): the score with its last week, open findings by severity,
 * each check with a scan button, the worst open findings and the deploy gate.
 */
definePageMeta({ layout: 'app', service: 'security' });
type OverviewPage = {
    overview: ProjectOverview;
    score: number;
    grade: 'A' | 'B' | 'C' | 'D' | 'F';
    trend: number[];
    intervalHours: number;
    bySeverity: Array<{ severity: string; label: string; count: number }>;
    checks: SecurityCheck[];
    recent: FindingRow[];
    environments: Array<{ id: string; name: string; gate: 'critical' | 'high' | null }>;
    gateIncluded: boolean;
    canManage: boolean;
};
const { t, tc, number } = useT();
const route = useRoute();
const { data } = await useApi<OverviewPage>(() => `/projects/${route.params.project}/security`);
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/security`);
const ring = computed(() => ({ A: 'border-emerald-500/80', B: 'border-sky-500/80', C: 'border-amber-500/80' } as Record<string, string>)[data.value.grade] ?? 'border-rose-500/80');
const change = computed(() => (data.value.trend.at(-1) ?? 0) - (data.value.trend[0] ?? 0));
const icons: Record<string, string> = { dependencies: 'package', secrets: 'key', servers: 'cpu', domains: 'globe', access: 'lockKey' };
const gateOptions = computed(() => [
    { value: '', label: t('Nothing (off)') },
    { value: 'critical', label: t('Critical vulnerabilities') },
    { value: 'high', label: t('High or critical vulnerabilities') },
]);
/** Say what an environment's deploy gate blocks. */
const gateLabel = (gate: string | null) => (gate === 'critical' ? t('Blocks critical vulnerabilities') : gate === 'high' ? t('Blocks high and critical vulnerabilities') : t('Off'));
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Security')" :description="t('Vulnerable packages, leaked secrets, server hardening, domains and attacks, checked every :hours hours on your plan.', { hours: data.intervalHours })">
            <template v-if="data.canManage" #actions>
                <ApiForm :action="`${base}/scans`">
                    <input type="hidden" name="kind" value="all">
                    <SubmitButton>{{ t('Scan everything now') }}</SubmitButton>
                </ApiForm>
            </template>
        </ProjectHeader>

        <div class="space-y-6">
            <div class="grid gap-6 xl:grid-cols-[22rem_1fr]">
                <AcmeCard :title="t('Security score')">
                    <div class="flex items-center gap-5">
                        <div :class="['grid size-28 shrink-0 place-items-center rounded-full border-[10px]', ring]">
                            <span class="text-center"><b class="block text-3xl font-semibold tabular-nums text-ink">{{ data.score }}</b><span class="text-xs text-muted">{{ t('Grade :grade', { grade: data.grade }) }}</span></span>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs text-muted">{{ t('Last 7 days') }}</p>
                            <AcmeSparkline :values="data.trend" :label="t('Security score trend')" area />
                            <p v-if="change !== 0" :class="['mt-1 text-xs', change > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400']">{{ t(':change since last week', { change: `${change > 0 ? '+' : '−'}${Math.abs(change)}` }) }}</p>
                            <p v-else class="mt-1 text-xs text-muted">{{ t('No change this week') }}</p>
                        </div>
                    </div>
                    <ul class="mt-5 grid grid-cols-3 gap-2 text-center">
                        <li v-for="row in data.bySeverity" :key="row.severity">
                            <NuxtLink :to="`/projects/${project.id}/security/findings?severity=${row.severity}`" class="block rounded-xl border border-line p-2 hover:border-accent/40">
                                <b :class="['block text-lg tabular-nums', row.count > 0 && row.severity !== 'medium' ? 'text-rose-600 dark:text-rose-400' : 'text-ink']">{{ number(row.count) }}</b>
                                <span class="text-[11px] text-muted">{{ row.label }}</span>
                            </NuxtLink>
                        </li>
                    </ul>
                </AcmeCard>
                <AcmeCard :title="t('Checks')" :description="t('Each check runs on its own schedule. Run one now after fixing something to see it clear.')" :padded="false">
                    <ul class="divide-y divide-line border-t border-line">
                        <li v-for="check in data.checks" :key="check.kind" class="flex flex-wrap items-center gap-3 px-5 py-3.5 sm:px-6">
                            <AcmeIconBubble :icon="icons[check.kind] ?? 'shield'" />
                            <span class="min-w-0 flex-1">
                                <b class="block text-sm font-medium text-ink">{{ check.label }}</b>
                                <span class="block truncate text-xs text-muted">
                                    <template v-if="!check.included">{{ t('Not on your plan.') }}</template>
                                    <template v-else-if="check.status === null">{{ t('Not run yet.') }}</template>
                                    <template v-else-if="check.status === 'queued' || check.status === 'running'">{{ t('Running now…') }}</template>
                                    <span v-else-if="check.status === 'failed'" class="text-rose-600 dark:text-rose-400">{{ t('Failed: :error', { error: check.error ?? t('unknown error') }) }}</span>
                                    <Rich v-else-if="check.finishedAt" :text="t('Ran :time')"><template #time><RelativeTime :at="check.finishedAt" /></template></Rich>
                                </span>
                            </span>
                            <AcmeBadge v-if="!check.included" tone="violet">{{ t('Pro plan') }}</AcmeBadge>
                            <AcmeBadge v-else-if="check.status === 'completed'" :tone="check.findings > 0 ? 'amber' : 'green'" dot>{{ check.findings > 0 ? tc(':count open|:count open', check.findings, { count: number(check.findings) }) : t('Clear') }}</AcmeBadge>
                            <ApiForm v-if="data.canManage && check.included" :action="`${base}/scans`">
                                <input type="hidden" name="kind" :value="check.kind">
                                <SubmitButton variant="quiet" size="sm">{{ t('Scan now') }}</SubmitButton>
                            </ApiForm>
                            <AcmeBtn v-else-if="!check.included" size="sm" variant="ghost" to="/account/billing?tab=security">{{ t('See plans') }}</AcmeBtn>
                        </li>
                        <li v-if="data.checks.length === 0" class="px-5 py-4 text-sm text-muted sm:px-6">{{ t('No checks are available yet.') }}</li>
                    </ul>
                </AcmeCard>
            </div>

            <AcmeCard :title="t('Most serious open findings')" :link="{ label: t('All findings'), to: `/projects/${project.id}/security/findings` }" :padded="false">
                <p v-if="data.recent.length === 0" class="px-5 pb-5 text-sm text-muted sm:px-6">{{ t('Nothing open. Checks keep running in the background.') }}</p>
                <ul v-else class="divide-y divide-line border-t border-line">
                    <FindingItem v-for="finding in data.recent" :key="finding.id" :finding="finding" :base="base" :can-manage="data.canManage" />
                </ul>
            </AcmeCard>

            <AcmeCard v-if="data.environments.length > 0" id="deploy-gate" :title="t('Deploy gate')" :description="t('Stop a deploy before it goes live when its packages have a known vulnerability at or above the level you choose. Ignoring a finding in Security accepts the risk and lets deploys through.')" :padded="false">
                <PlanNotice v-if="!data.gateIncluded" class="mx-5 mb-4 sm:mx-6" :message="t('The deploy gate comes with the Pro Security plan and above.')" />
                <ul class="divide-y divide-line border-t border-line">
                    <li v-for="environment in data.environments" :key="environment.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 sm:px-6">
                        <span><b class="block font-medium text-ink">{{ environment.name }}</b><span class="text-sm text-muted">{{ gateLabel(environment.gate) }}</span></span>
                        <FormDialog
                            v-if="data.canManage && data.gateIncluded"
                            :id="`gate-${environment.id}`"
                            :title="t('Deploy gate for :environment', { environment: environment.name })"
                            :description="t('Deploys stop before going live when a package has a known vulnerability at this level or above.')"
                            :action="`${base}/gate/${environment.id}`"
                            method="PUT"
                            :submit="t('Save')"
                        >
                            <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Change') }}</AcmeBtn></template>
                            <SelectField :id="`gate-${environment.id}-level`" name="security_gate" :label="t('Block deploys with')" :options="gateOptions" :model-value="environment.gate ?? ''" />
                        </FormDialog>
                    </li>
                </ul>
            </AcmeCard>
        </div>
    </div>
</template>
