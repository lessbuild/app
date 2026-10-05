<script setup lang="ts">
import type { MenuItem } from '~/components/acme/Menu.vue';
import type { BuildLiveStatus, BuildPage, ObservationMeasures } from '~/types/deploy';

/**
 * One deploy (the Acme theme's deploy page): its status (followed live while it runs), approval inline, the stages
 * beside the log, release analysis and notes, and the actions on it (redeploy, roll back, promote, cancel) in a menu.
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
// Lower is better for failures and request time; higher for the rest.
const lowerIsBetter = new Set<keyof ObservationMeasures>(['error_rate', 'latency_ms']);
const better = (key: keyof ObservationMeasures, before: number | null | undefined, after: number | null | undefined) => (before == null || after == null || before === after ? null : lowerIsBetter.has(key) ? after < before : after > before);
const actions = computed<MenuItem[]>(() => {
    if (!data.value.canDeploy) {
        return [];
    }
    const items: MenuItem[] = [];
    if (!active.value && build.value.revision) {
        items.push({ label: t('Redeploy this commit'), icon: 'refresh', onSelect: () => submit('redeploy-form') });
    }
    if (status.value === 'succeeded' && build.value.releaseName) {
        items.push({ label: t('Make this release live again'), icon: 'arrowUp', onSelect: () => submit('rollback-form') });
    }
    if (data.value.promotionTargets.length > 0) {
        items.push({ label: t('Promote this commit…'), icon: 'transfer', onSelect: () => navigateTo({ query: { ...route.query, dialog: 'promote' } }) });
    }
    if (active.value) {
        items.push({ divider: true }, { label: t('Cancel deploy'), icon: 'close', danger: true, onSelect: () => submit('cancel-form') });
    }
    return items;
});

/**
 * Send one of the page's hidden action forms (they ask to confirm and check identity as any form does).
 *
 * @param id The form's id.
 */
function submit(id: string) {
    (document.getElementById(id) as HTMLFormElement | null)?.requestSubmit();
}

/** Save the log as a text file. */
function download() {
    const link = Object.assign(document.createElement('a'), { href: URL.createObjectURL(new Blob([log.value ?? ''], { type: 'text/plain' })), download: `deploy-${build.value.id}.log` });
    link.click();
    URL.revokeObjectURL(link.href);
}
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
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Deploy #:id', { id: build.id })" :description="`${(build.commitMessage ?? '').split('\n')[0] || build.repository.name} · ${build.repository.name} → ${build.website}`">
            <template #actions>
                <AcmeBtn :to="`/projects/${project.id}/deploy/builds/${build.id}/compare`" icon="layers">{{ t('Compare') }}</AcmeBtn>
                <AcmeBtn v-if="build.log" :to="`/api/app/projects/${project.id}/deploy/builds/${build.id}/log`" external icon="download">{{ t('Download log') }}</AcmeBtn>
                <AcmeMenu v-if="actions.length > 0" :items="actions" :label="t('More actions')" icon="dots" align="right" />
            </template>
        </ProjectHeader>
        <div class="space-y-6">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5" :aria-busy="active || undefined">
                <div class="rounded-2xl border border-line bg-surface p-4 shadow-card xl:col-span-2">
                    <p class="text-xs text-muted">{{ t('Status') }}</p>
                    <p class="mt-2" role="status" aria-live="polite"><BuildStatusBadge :status="status" /></p>
                    <p class="mt-2 text-xs text-muted"><RelativeTime v-if="build.startedAt" :at="build.startedAt" /><template v-else>—</template> · {{ t('took :time', { time: took }) }}</p>
                </div>
                <div class="rounded-2xl border border-line bg-surface p-4 shadow-card">
                    <p class="text-xs text-muted">{{ t('Revision') }}</p>
                    <p class="mt-2 truncate font-mono font-medium text-ink"><a v-if="build.shortRevision && build.revisionUrl" :href="build.revisionUrl" target="_blank" rel="noopener" class="hover:underline">{{ build.shortRevision }}</a><template v-else>{{ build.shortRevision ?? '—' }}</template></p>
                    <p v-if="build.ref" class="mt-1 truncate text-xs text-muted">{{ build.ref }}</p>
                </div>
                <div class="rounded-2xl border border-line bg-surface p-4 shadow-card">
                    <p class="text-xs text-muted">{{ t('Started by') }}</p>
                    <p class="mt-2 truncate font-medium text-ink">{{ labels.trigger(build.trigger) }} · {{ build.requester ?? t('A push') }}</p>
                </div>
                <div class="rounded-2xl border border-line bg-surface p-4 shadow-card">
                    <p class="text-xs text-muted">{{ t('Release') }}</p>
                    <p class="mt-2 truncate font-mono text-sm font-medium text-ink">{{ build.releaseName ?? '—' }}</p>
                </div>
            </div>

            <AcmeAlert v-if="build.failureMessage" tone="danger">{{ build.failureMessage }}</AcmeAlert>
            <AcmeAlert v-if="build.rolledBackFrom || build.redeployedFrom || build.promotedFrom || build.promotions.length > 0" tone="info">
                <p v-if="build.rolledBackFrom">{{ t('Rolled back to the release from deploy #:id.', { id: build.rolledBackFrom }) }}</p>
                <p v-if="build.redeployedFrom">{{ t('Redeploy of #:id.', { id: build.redeployedFrom }) }}</p>
                <p v-if="build.promotedFrom">
                    {{ t('Promoted from deploy #:id in :environment.', { id: build.promotedFrom.id, environment: build.promotedFrom.environment ?? '—' }) }}
                    <template v-if="build.promotedFrom.note"> {{ t('Note: :note', { note: build.promotedFrom.note }) }}</template>
                </p>
                <p v-for="promotion in build.promotions" :key="promotion.id">
                    {{ t('Promoted to :environment as', { environment: promotion.environment ?? '—' }) }}
                    <NuxtLink :to="`/projects/${project.id}/deploy/builds/${promotion.id}`" class="font-medium underline">#{{ promotion.id }}</NuxtLink>.
                </p>
            </AcmeAlert>

            <section v-if="status === 'awaiting_approval' || build.destructiveMigrations" class="rounded-2xl border border-amber-500/40 bg-amber-500/[.05] p-5 sm:p-6" aria-labelledby="approve-title">
                <h2 id="approve-title" class="flex items-center gap-2 font-semibold text-ink"><AcmeIcon name="alert" :size="18" class="text-amber-600" />{{ t('Approve deploy #:id', { id: build.id }) }}</h2>
                <template v-if="build.destructiveMigrations">
                    <p class="mt-1 text-sm text-muted">{{ t('The deploy stopped before running these. Check they’re intended and that nothing still reads what they remove.') }}</p>
                    <pre class="mt-4 max-h-64 overflow-auto rounded-xl bg-zinc-950 p-4 font-mono text-xs leading-6 text-rose-200">{{ build.destructiveMigrations }}</pre>
                    <ApiForm v-if="data.canApprove && status === 'failed'" :action="`${base}/approve-migrations`" :confirm="t('Run these migrations and deploy?')" class="mt-4 !block">
                        <SubmitButton variant="danger">{{ t('Approve these migrations and deploy') }}</SubmitButton>
                    </ApiForm>
                </template>
                <template v-if="status === 'awaiting_approval'">
                    <ApiForm v-if="data.canApprove" :action="`${base}/review`" class="mt-4 !flex flex-wrap items-end gap-3">
                        <InputField id="review-note" name="note" :label="t('Note (optional)')" maxlength="1000" class="min-w-60 max-w-xl flex-1" />
                        <SubmitButton name="decision" value="approve">{{ t('Approve and deploy') }}</SubmitButton>
                        <SubmitButton name="decision" value="reject" variant="secondary">{{ t('Reject') }}</SubmitButton>
                    </ApiForm>
                    <p v-else class="mt-2 text-sm text-muted">{{ t('Waiting for someone else with deploy rights to approve it.') }}</p>
                </template>
            </section>
            <p v-if="build.approvalNote" class="text-sm text-muted">{{ t('Note: :note', { note: build.approvalNote }) }}</p>
            <div v-if="build.note || data.canDeploy" class="flex flex-wrap items-start justify-between gap-3 rounded-2xl border border-line bg-surface px-5 py-4 shadow-card">
                <div class="min-w-0">
                    <p class="text-xs font-medium text-muted">{{ t('Team note') }}</p>
                    <p :class="['mt-1 whitespace-pre-line text-sm', build.note ? 'text-ink' : 'text-muted']">{{ build.note ?? t('Add a note for your team: why this deploy happened, or what to watch.') }}</p>
                </div>
                <FormDialog v-if="data.canDeploy" id="build-note" :title="t('Team note')" :action="`/api/app/projects/${project.id}/deploy/builds/${build.id}/note`" method="PUT" :submit="t('Save note')">
                    <template #trigger="{ open: show }"><AcmeBtn size="sm" icon="edit" @click="show">{{ build.note ? t('Edit note') : t('Add note') }}</AcmeBtn></template>
                    <TextareaField id="build-note-text" name="note" :label="t('Note')" rows="4" maxlength="500" :model-value="build.note ?? ''" :description="t('Seen by everyone who can see this deploy. Leave empty to remove it.')" />
                </FormDialog>
            </div>

            <div class="grid gap-6 xl:grid-cols-[22rem_1fr]">
                <AcmeCard :title="t('Steps')">
                    <ol v-if="showStages && data.stages.length > 0" class="space-y-1">
                        <li v-for="(title, index) in data.stages" :key="index" :class="['flex items-center gap-3 rounded-lg px-2 py-2 text-sm', status === 'failed' && stage === index && 'bg-rose-500/[.06]']">
                            <AcmeIcon v-if="stage > index" name="checkCircle" :size="17" class="text-emerald-600" />
                            <AcmeIcon v-else-if="status === 'failed' && stage === index" name="circleX" :size="17" class="text-rose-600" />
                            <AcmeIcon v-else-if="active && stage === index" name="circleDashed" :size="17" class="animate-spin text-sky-600" />
                            <AcmeIcon v-else name="circle" :size="17" class="text-muted" />
                            <span :class="['flex-1', stage > index || (active && stage === index) ? 'text-ink' : 'text-muted']">{{ title }}</span>
                        </li>
                    </ol>
                    <p v-else class="text-sm text-muted">{{ build.trigger === 'rollback' ? t('A rollback switches to the earlier release; there’s nothing to build.') : t('The steps show once the deploy starts.') }}</p>
                </AcmeCard>
                <AcmeCard :title="t('Log')" :description="active ? t('Follows the deploy live.') : t('The end of the deployment log.')" :padded="false">
                    <template #action><AcmeBtn v-if="log" size="sm" variant="ghost" icon="download" @click="download">{{ t('Download') }}</AcmeBtn></template>
                    <pre v-if="log" ref="logBox" class="max-h-[32rem] overflow-auto rounded-b-2xl bg-zinc-950 px-5 py-4 font-mono text-xs leading-6 text-zinc-300" aria-live="off">{{ log }}</pre>
                    <p v-else class="px-5 pb-5 text-sm text-muted sm:px-6">{{ t('No log yet.') }}</p>
                </AcmeCard>
            </div>

            <AcmeCard v-if="build.observation" :title="t('Release analysis')" :description="t('Before and after this release went live, from Monitoring and Analytics.')">
                <template #action><AcmeBadge :tone="acmeTone(observationBadge.tone)">{{ observationBadge.label }}</AcmeBadge></template>
                <p v-if="build.observation.error" class="mb-4 text-sm text-rose-600">{{ build.observation.error }}</p>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <template v-for="measure in measures" :key="measure.key">
                        <div v-if="build.observation.before[measure.key] != null || build.observation.after[measure.key] != null" class="rounded-xl border border-line p-4">
                            <p class="text-xs text-muted">{{ measure.label }}</p>
                            <p class="mt-2 flex items-baseline gap-2"><span class="text-sm text-muted line-through">{{ build.observation.before[measure.key] ?? '—' }}</span><AcmeIcon name="arrowRight" :size="12" class="text-muted" /><span class="text-xl font-semibold tabular-nums text-ink">{{ build.observation.after[measure.key] ?? '—' }}</span></p>
                            <p v-if="better(measure.key, build.observation.before[measure.key], build.observation.after[measure.key]) !== null" :class="['mt-1 text-xs', better(measure.key, build.observation.before[measure.key], build.observation.after[measure.key]) ? 'text-emerald-600' : 'text-rose-600']">
                                {{ better(measure.key, build.observation.before[measure.key], build.observation.after[measure.key]) ? t('Better than before') : t('Worse than before') }}
                            </p>
                        </div>
                    </template>
                </div>
                <div v-if="data.telemetry" class="mt-4 flex flex-wrap gap-2">
                    <AcmeBtn size="sm" icon="alert" :to="`/projects/${project.id}/monitoring/deployments/${data.telemetry.id}`">{{ t('Errors and latency') }}</AcmeBtn>
                    <AcmeBtn size="sm" icon="list" :to="`/projects/${project.id}/monitoring/events?release=${data.telemetry.releaseId}&environment=${data.telemetry.environmentId}&has_trace=yes&range=all`">{{ t('Requests and traces') }}</AcmeBtn>
                </div>
            </AcmeCard>

            <AcmeCard v-if="Object.keys(build.releaseNotes).length > 0" :title="t('Release notes')" :description="tc(':count commit|:count commits', build.commitCount, { count: build.commitCount })">
                <ReleaseNotes :sections="build.releaseNotes" />
            </AcmeCard>
        </div>

        <FormDialog v-if="data.promotionTargets.length > 0" id="promote" :title="t('Promote this commit')" :description="t('Deploys :revision to another environment with that environment’s own settings and approvals.', { revision: build.shortRevision ?? '' })" :action="`${base}/promote`" :submit="t('Promote')">
            <SelectField id="promote-environment" name="environment_id" :label="t('Environment')" :options="data.promotionTargets" />
            <InputField id="promote-note" name="note" :label="t('Note (optional)')" maxlength="2000" />
        </FormDialog>
        <div class="hidden">
            <ApiForm id="redeploy-form" :action="`${base}/redeploy`" />
            <ApiForm id="rollback-form" :action="`${base}/rollback`" :confirm="t('Make this release live again?')" />
            <ApiForm id="cancel-form" :action="`${base}/cancel`" :confirm="t('Cancel this deploy?')" />
        </div>
    </div>
</template>
