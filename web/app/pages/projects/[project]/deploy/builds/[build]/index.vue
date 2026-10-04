<script setup lang="ts">
import type { BuildLiveStatus, BuildPage, ObservationMeasures } from '~/types/deploy';

/**
 * One deploy: its status (followed live while it runs), commit, release notes, approvals, release analysis,
 * promotion to another environment, the actions on it (cancel, redeploy, roll back) and its log.
 */
definePageMeta({ layout: 'app', service: 'deploy' });
const { t, tc } = useT();
const labels = useDeployLabels();
const route = useRoute();
const { data } = await useApi<BuildPage>(() => `/projects/${route.params.project}/deploy/builds/${route.params.build}`);
const build = computed(() => data.value.build);
const project = computed(() => data.value.overview.project);
const base = computed(() => `/api/app/projects/${project.value.id}/deploy/builds/${build.value.id}`);
const live = ref<BuildLiveStatus | null>(null);
const status = computed(() => live.value?.status ?? build.value.status);
const stage = computed(() => live.value?.stage ?? build.value.setupStage);
const log = computed(() => live.value?.log ?? build.value.log);
const active = computed(() => (live.value ? !live.value.finished : build.value.active));
const took = computed(() => (build.value.startedAt && build.value.finishedAt ? labels.duration(Math.round((Date.parse(build.value.finishedAt) - Date.parse(build.value.startedAt)) / 1000)) : '—'));
const showStages = computed(() => (active.value || ['succeeded', 'failed', 'canceled'].includes(status.value)) && build.value.trigger !== 'rollback');
const measures = computed<Array<{ key: keyof ObservationMeasures; label: string }>>(() => [
    { key: 'requests', label: t('Requests') },
    { key: 'error_rate', label: t('Failed requests (%)') },
    { key: 'latency_ms', label: t('Average request time (ms)') },
    { key: 'visits', label: t('Visits') },
    { key: 'conversion_rate', label: t('Conversion rate (%)') },
]);
const observationBadge = computed(() => {
    switch (build.value.observation?.status) {
        case 'passed':
            return { tone: 'success' as const, label: t('Passed') };
        case 'failed':
            return { tone: 'danger' as const, label: t('Failed') };
        default:
            return { tone: 'neutral' as const, label: t('Watching') };
    }
});
const logBox = ref<HTMLElement | null>(null);
let timer: number | undefined;

/** Ask how the deploy is going; once it's finished, load the whole page again. */
async function poll() {
    try {
        live.value = await send<BuildLiveStatus>('GET', `/projects/${project.value.id}/deploy/builds/${build.value.id}/status`);
    } catch {
        return;
    }
    if (live.value.finished) {
        stop();
        live.value = null;
        await refreshPage();
    }
}

function stop() {
    if (timer !== undefined) {
        window.clearInterval(timer);
        timer = undefined;
    }
}

// While it runs, follow it every few seconds; a finished deploy needs nothing more.
watch(() => build.value.active && build.value.id, (following) => {
    stop();
    if (following && import.meta.client) {
        timer = window.setInterval(poll, 3000);
    }
}, { immediate: true });
onBeforeUnmount(stop);

// Keep the end of the log in view as it grows, unless they've scrolled up to read.
watch(log, async () => {
    const box = logBox.value;
    const atEnd = box !== null && box.scrollHeight - box.scrollTop - box.clientHeight < 40;
    await nextTick();
    if (box && atEnd) {
        box.scrollTop = box.scrollHeight;
    }
});
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Deploy #:id', { id: build.id })" :description="`${build.repository.name} → ${build.website}`">
            <template #actions>
                <template v-if="data.telemetry">
                    <UiButton :to="`/projects/${project.id}/monitoring/deployments/${data.telemetry.id}`" variant="quiet" size="sm">{{ t('Errors and latency') }}</UiButton>
                    <UiButton :to="`/projects/${project.id}/monitoring/events?release=${data.telemetry.releaseId}&environment=${data.telemetry.environmentId}&has_trace=yes&range=all`" variant="quiet" size="sm">{{ t('Requests and traces') }}</UiButton>
                </template>
                <UiButton :to="`/projects/${project.id}/deploy/builds/${build.id}/compare`" variant="quiet" size="sm">{{ t('Compare') }}</UiButton>
            </template>
        </ProjectHeader>

        <section class="ui-card grid gap-5 p-5 sm:p-6" :aria-busy="active || undefined">
            <div class="flex flex-wrap items-center gap-3">
                <span role="status" aria-live="polite"><BuildStatusBadge :status="status" /></span>
                <a v-if="build.shortRevision && build.revisionUrl" :href="build.revisionUrl" target="_blank" rel="noopener" class="font-mono text-sm text-primary hover:underline">{{ build.shortRevision }}</a>
                <span v-else-if="build.shortRevision" class="font-mono text-sm">{{ build.shortRevision }}</span>
                <Badge v-if="build.ref" tone="accent" :title="t('Requested version')">{{ build.ref }}</Badge>
                <span class="min-w-0 text-sm text-ink">{{ (build.commitMessage ?? '').split('\n')[0] }}</span>
            </div>
            <dl class="grid gap-4 text-sm sm:grid-cols-4">
                <div><dt class="text-xs text-muted">{{ t('Started by') }}</dt><dd class="mt-1">{{ build.requester ?? t('A push') }} · {{ labels.trigger(build.trigger) }}</dd></div>
                <div><dt class="text-xs text-muted">{{ t('Started') }}</dt><dd class="mt-1"><RelativeTime v-if="build.startedAt" :at="build.startedAt" /><template v-else>—</template></dd></div>
                <div><dt class="text-xs text-muted">{{ t('Took') }}</dt><dd class="mt-1">{{ took }}</dd></div>
                <div><dt class="text-xs text-muted">{{ t('Release') }}</dt><dd class="mt-1 break-all font-mono text-xs">{{ build.releaseName ?? '—' }}</dd></div>
            </dl>

            <div v-if="build.rolledBackFrom || build.redeployedFrom || build.promotedFrom || build.promotions.length > 0" class="grid gap-1 text-sm text-muted">
                <p v-if="build.rolledBackFrom">{{ t('Rolled back to the release from deploy #:id.', { id: build.rolledBackFrom }) }}</p>
                <p v-if="build.redeployedFrom">{{ t('Redeploy of #:id.', { id: build.redeployedFrom }) }}</p>
                <p v-if="build.promotedFrom">
                    {{ t('Promoted from deploy #:id in :environment.', { id: build.promotedFrom.id, environment: build.promotedFrom.environment ?? '—' }) }}
                    <template v-if="build.promotedFrom.note"> {{ t('Note: :note', { note: build.promotedFrom.note }) }}</template>
                </p>
                <p v-for="promotion in build.promotions" :key="promotion.id">
                    {{ t('Promoted to :environment as', { environment: promotion.environment ?? '—' }) }}
                    <NuxtLink :to="`/projects/${project.id}/deploy/builds/${promotion.id}`" class="font-bold text-primary hover:underline">#{{ promotion.id }}</NuxtLink>.
                </p>
            </div>
            <Alert v-if="build.failureMessage" tone="danger">{{ build.failureMessage }}</Alert>

            <section v-if="Object.keys(build.releaseNotes).length > 0" class="grid gap-2" aria-labelledby="release-notes">
                <h2 id="release-notes" class="text-sm font-bold text-ink">
                    {{ t('Release notes') }} <span class="font-normal text-muted">· {{ tc(':count commit|:count commits', build.commitCount, { count: build.commitCount }) }}</span>
                </h2>
                <ReleaseNotes :sections="build.releaseNotes" />
            </section>

            <section v-if="build.destructiveMigrations" class="grid gap-2" aria-labelledby="destructive-migrations">
                <h2 id="destructive-migrations" class="text-sm font-bold text-ink">{{ t('Destructive migrations') }}</h2>
                <p class="text-sm text-muted">{{ t('The deploy stopped before running these. Check they’re intended and that nothing still reads what they remove.') }}</p>
                <CodeBlock :code="build.destructiveMigrations" class="max-h-64 overflow-auto whitespace-pre-wrap text-xs" />
                <ApiForm v-if="data.canApprove && status === 'failed'" :action="`${base}/approve-migrations`" :confirm="t('Run these migrations and deploy?')">
                    <SubmitButton variant="danger">{{ t('Approve these migrations and deploy') }}</SubmitButton>
                </ApiForm>
            </section>

            <section v-if="build.observation" class="grid gap-2" aria-labelledby="release-analysis">
                <h2 id="release-analysis" class="flex items-center gap-2 text-sm font-bold text-ink">{{ t('Release analysis') }} <Badge :tone="observationBadge.tone">{{ observationBadge.label }}</Badge></h2>
                <p v-if="build.observation.error" class="text-sm text-danger">{{ build.observation.error }}</p>
                <DataTable :caption="t('Before and after this release went live')" :framed="false">
                    <template #head>
                        <tr><th scope="col">{{ t('Measure') }}</th><th scope="col" class="text-right">{{ t('Before') }}</th><th scope="col" class="text-right">{{ t('After') }}</th></tr>
                    </template>
                    <template v-for="measure in measures" :key="measure.key">
                        <tr v-if="build.observation.before[measure.key] != null || build.observation.after[measure.key] != null">
                            <td>{{ measure.label }}</td>
                            <td class="text-right tabular-nums">{{ build.observation.before[measure.key] ?? '—' }}</td>
                            <td class="text-right tabular-nums">{{ build.observation.after[measure.key] ?? '—' }}</td>
                        </tr>
                    </template>
                </DataTable>
            </section>
            <p v-if="build.approvalNote" class="text-sm text-muted">{{ t('Note: :note', { note: build.approvalNote }) }}</p>

            <template v-if="status === 'awaiting_approval'">
                <ApiForm v-if="data.canApprove" :action="`${base}/review`" class="flex flex-wrap items-end gap-3 rounded-panel border border-warning/40 bg-warning-soft/40 p-4">
                    <div class="min-w-60 flex-1"><InputField name="note" :label="t('Note (optional)')" maxlength="1000" /></div>
                    <SubmitButton name="decision" value="approve">{{ t('Approve') }}</SubmitButton>
                    <SubmitButton name="decision" value="reject" variant="secondary">{{ t('Reject') }}</SubmitButton>
                </ApiForm>
                <p v-else class="text-sm text-muted">{{ t('Waiting for someone else with deploy rights to approve it.') }}</p>
            </template>

            <ApiForm v-if="data.promotionTargets.length > 0" :action="`${base}/promote`" class="flex flex-wrap items-end gap-3 border-t border-line pt-4">
                <SelectField name="environment_id" :label="t('Promote this commit to')" :options="data.promotionTargets" />
                <div class="min-w-60 flex-1"><InputField name="note" :label="t('Note (optional)')" maxlength="2000" /></div>
                <SubmitButton>{{ t('Promote') }}</SubmitButton>
            </ApiForm>

            <div v-if="data.canDeploy" class="flex flex-wrap gap-2 border-t border-line pt-4">
                <ApiForm v-if="active" :action="`${base}/cancel`" :confirm="t('Cancel this deploy?')"><SubmitButton variant="secondary" size="sm">{{ t('Cancel deploy') }}</SubmitButton></ApiForm>
                <ApiForm v-if="!active && build.revision" :action="`${base}/redeploy`"><SubmitButton variant="secondary" size="sm">{{ t('Redeploy this commit') }}</SubmitButton></ApiForm>
                <ApiForm v-if="status === 'succeeded' && build.releaseName" :action="`${base}/rollback`" :confirm="t('Make this release live again?')"><SubmitButton variant="quiet" size="sm">{{ t('Make this release live again') }}</SubmitButton></ApiForm>
            </div>
        </section>

        <section v-if="showStages && data.stages.length > 0" class="ui-card p-5">
            <ol class="grid gap-2 text-sm sm:grid-cols-3" :aria-label="t('Stages')">
                <li v-for="(title, index) in data.stages" :key="index" :class="['flex items-center gap-2', stage > index ? 'text-ink' : 'text-muted']">
                    <Icon v-if="stage > index" name="check" class="h-4 w-4 text-success" />
                    <span v-else-if="active && stage === index" class="ui-status-dot animate-pulse" aria-hidden="true" />
                    <span v-else class="grid h-4 w-4 place-items-center" aria-hidden="true">·</span>
                    {{ title }}
                </li>
            </ol>
        </section>

        <SettingsSection :title="t('Log')" :description="active ? t('Follows the deploy live.') : t('The end of the deployment log.')">
            <div v-if="log" ref="logBox" class="m-4 max-h-[32rem] overflow-auto sm:m-6">
                <CodeBlock :code="log" class="whitespace-pre-wrap" aria-live="off" />
            </div>
            <p v-else class="p-4 text-sm text-muted sm:p-6">{{ t('No log yet.') }}</p>
        </SettingsSection>
    </div>
</template>
