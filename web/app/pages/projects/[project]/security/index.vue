<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';
import type { FindingRow, SecurityCheck } from '~/types/security';

/** Security's overview: the score, open findings by severity, each check with a scan button, the deploy gate and the worst open findings. */
definePageMeta({ layout: 'app', service: 'security' });
type OverviewPage = {
    overview: ProjectOverview;
    score: number;
    grade: 'A' | 'B' | 'C' | 'D' | 'F';
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
const gradeTone = computed(() => ({ A: 'success', B: 'info', C: 'warning' } as const)[data.value.grade as 'A' | 'B' | 'C'] ?? 'danger');
const gateOptions = computed(() => [
    { value: '', label: t('Nothing (off)') },
    { value: 'critical', label: t('Critical vulnerabilities') },
    { value: 'high', label: t('High or critical vulnerabilities') },
]);
/** Say what an environment's deploy gate blocks. */
const gateLabel = (gate: string | null) => (gate === 'critical' ? t('Blocks critical vulnerabilities') : gate === 'high' ? t('Blocks high and critical vulnerabilities') : t('Off'));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Security')" :description="t('Vulnerable packages, leaked secrets, server hardening, domains and attacks, checked every :hours hours on your plan.', { hours: data.intervalHours })" />

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="ui-card p-5">
                <p class="text-xs font-bold text-muted">{{ t('Security score') }}</p>
                <p class="mt-2 flex items-baseline gap-3"><span class="text-4xl font-extrabold tracking-tight text-ink tabular-nums">{{ data.score }}</span><Badge :tone="gradeTone">{{ t('Grade :grade', { grade: data.grade }) }}</Badge></p>
            </div>
            <NuxtLink v-for="row in data.bySeverity" :key="row.severity" :to="`/projects/${project.id}/security/findings?severity=${row.severity}`" class="ui-card block p-5 transition hover:border-line-strong">
                <p class="text-xs font-bold text-muted">{{ row.label }}</p>
                <p class="mt-2 text-3xl font-extrabold tracking-tight text-ink tabular-nums">{{ number(row.count) }}</p>
            </NuxtLink>
        </div>

        <SettingsSection :title="t('Checks')" :description="t('Each check runs on its own schedule. Run one now after fixing something to see it clear.')">
            <section class="ui-card overflow-hidden">
                <h2 class="sr-only">{{ t('Checks') }}</h2>
                <ul class="divide-y divide-line">
                    <li v-for="check in data.checks" :key="check.kind" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <div class="min-w-0">
                            <p class="font-bold text-ink">{{ check.label }}</p>
                            <p class="text-sm text-muted">
                                <template v-if="!check.included">{{ t('Not on your plan.') }} <NuxtLink to="/account/billing?tab=security" class="ui-link">{{ t('See plans') }}</NuxtLink></template>
                                <template v-else-if="check.status === null">{{ t('Not run yet.') }}</template>
                                <template v-else-if="check.status === 'queued' || check.status === 'running'">{{ t('Running now…') }}</template>
                                <span v-else-if="check.status === 'failed'" class="text-danger">{{ t('Failed: :error', { error: check.error ?? t('unknown error') }) }}</span>
                                <template v-else>
                                    <Rich v-if="check.finishedAt" :text="t('Ran :time')"><template #time><RelativeTime :at="check.finishedAt" /></template></Rich>
                                    · {{ tc(':count open finding|:count open findings', check.findings, { count: number(check.findings) }) }}
                                </template>
                            </p>
                        </div>
                        <ApiForm v-if="data.canManage && check.included" :action="`${base}/scans`">
                            <input type="hidden" name="kind" :value="check.kind">
                            <SubmitButton variant="secondary" size="sm">{{ t('Scan now') }}</SubmitButton>
                        </ApiForm>
                    </li>
                    <li v-if="data.checks.length === 0" class="px-5 py-4 text-sm text-muted">{{ t('No checks are available yet.') }}</li>
                </ul>
            </section>
        </SettingsSection>

        <SettingsSection v-if="data.environments.length > 0" :title="t('Deploy gate')" :description="t('Stop a deploy before it goes live when its packages have a known vulnerability at or above the level you choose. Ignoring a finding in Security accepts the risk and lets deploys through.')">
            <section id="deploy-gate" class="ui-card overflow-hidden">
                <h2 class="sr-only">{{ t('Deploy gate') }}</h2>
                <PlanNotice v-if="!data.gateIncluded" class="m-4" :message="t('The deploy gate comes with the Pro Security plan and above.')" />
                <ul class="divide-y divide-line">
                    <li v-for="environment in data.environments" :key="environment.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <div>
                            <p class="font-bold text-ink">{{ environment.name }}</p>
                            <p class="text-sm text-muted">{{ gateLabel(environment.gate) }}</p>
                        </div>
                        <FormDialog
                            v-if="data.canManage && data.gateIncluded"
                            :id="`gate-${environment.id}`"
                            :title="t('Deploy gate for :environment', { environment: environment.name })"
                            :description="t('Deploys stop before going live when a package has a known vulnerability at this level or above.')"
                            :action="`${base}/gate/${environment.id}`"
                            method="PUT"
                            :submit="t('Save')"
                        >
                            <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Change') }}</UiButton></template>
                            <SelectField :id="`gate-${environment.id}-level`" name="security_gate" :label="t('Block deploys with')" :options="gateOptions" :model-value="environment.gate ?? ''" />
                        </FormDialog>
                    </li>
                </ul>
            </section>
        </SettingsSection>

        <section class="ui-card overflow-hidden" aria-labelledby="top-findings">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4">
                <h2 id="top-findings" class="text-lg font-extrabold text-ink">{{ t('Most serious open findings') }}</h2>
                <UiButton :to="`/projects/${project.id}/security/findings`" variant="quiet" size="sm">{{ t('All findings') }}</UiButton>
            </div>
            <p v-if="data.recent.length === 0" class="px-5 py-4 text-sm text-muted">{{ t('Nothing open. Checks keep running in the background.') }}</p>
            <ul v-else class="divide-y divide-line">
                <FindingItem v-for="finding in data.recent" :key="finding.id" :finding="finding" :base="base" :can-manage="data.canManage" />
            </ul>
        </section>
    </div>
</template>
