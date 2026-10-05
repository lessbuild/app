<script setup lang="ts">
import type { BuildChange, ComparedBuild, ComparisonPage } from '~/types/deploy';

/**
 * A deploy beside another of the same repository (the Acme theme's compare page; the last good one unless `?with=`
 * picks one): timing, code and the
 * environment settings that changed between them. Secret values are compared by name only.
 */
definePageMeta({ layout: 'app', service: 'deploy' });
const { t, dateTime } = useT();
const labels = useDeployLabels();
const route = useRoute();
const { data } = await useApi<ComparisonPage>(
    () => `/projects/${route.params.project}/deploy/builds/${route.params.build}/compare`,
    () => (typeof route.query.with === 'string' ? { with: route.query.with } : {}),
);
const project = computed(() => data.value.overview.project);
const build = computed(() => data.value.build);
const baseline = computed(() => data.value.baseline);
const comparison = computed(() => data.value.comparison);
const statuses = computed<Record<string, string>>(() => ({ succeeded: t('Live'), failed: t('Failed'), rejected: t('Rejected'), canceled: t('Canceled'), running: t('Deploying'), deploying: t('Deploying'), awaiting_approval: t('Waiting for approval'), queued: t('Queued') }));
const candidates = computed(() => data.value.candidates.map((candidate) => ({
    value: String(candidate.id),
    label: `#${candidate.id} · ${statuses.value[candidate.status] ?? candidate.status} · ${candidate.revision ?? '—'} · ${(candidate.commitMessage ?? '').split('\n')[0]?.slice(0, 50) ?? ''}`,
})));
const chosen = ref<string | null>(baseline.value ? String(baseline.value.id) : null);
watch(chosen, (value) => {
    if (value && value !== String(baseline.value?.id)) {
        navigateTo({ query: { with: value } });
    }
});
const kinds = computed<Record<BuildChange['kind'], { tone: 'green' | 'red' | 'amber'; label: string }>>(() => ({
    added: { tone: 'green', label: t('Added') },
    removed: { tone: 'red', label: t('Removed') },
    changed: { tone: 'amber', label: t('Changed') },
}));
const areas = computed<Record<string, string>>(() => ({ Runtime: t('Runtime'), Variables: t('Variables'), 'Build variables': t('Build variables'), Workers: t('Workers'), Resources: t('Resources'), 'Environment file': t('Environment file') }));
const startedBy = (side: ComparedBuild) => `${side.requester ?? t('A push')} · ${labels.trigger(side.trigger)}`;
const rows = computed(() => {
    const before = baseline.value;
    if (!before) {
        return [];
    }
    const after = build.value;
    return [
        { label: t('Status'), before: statuses.value[before.status] ?? before.status, after: statuses.value[after.status] ?? after.status },
        { label: t('Commit'), before: before.shortRevision ?? '—', after: after.shortRevision ?? '—' },
        { label: t('Message'), before: before.commitMessage ?? '—', after: after.commitMessage ?? '—' },
        { label: t('Started by'), before: startedBy(before), after: startedBy(after) },
        { label: t('Environment'), before: before.environment ?? '—', after: after.environment ?? '—' },
        { label: t('Started'), before: before.startedAt ? dateTime(before.startedAt) : '—', after: after.startedAt ? dateTime(after.startedAt) : '—' },
        { label: t('Took'), before: labels.duration(before.seconds), after: labels.duration(after.seconds) },
        { label: t('Note'), before: before.note ?? '—', after: after.note ?? '—' },
        { label: t('Failure'), before: before.failure ?? '—', after: after.failure ?? '—' },
    ];
});
const timing = computed(() => {
    const delta = comparison.value?.durationDelta;
    const id = baseline.value?.id ?? '';
    if (delta === null || delta === undefined) {
        return t('Timing unavailable');
    }
    if (delta > 0) {
        return t(':seconds s slower than #:id', { seconds: delta, id });
    }
    return delta < 0 ? t(':seconds s faster than #:id', { seconds: Math.abs(delta), id }) : t('Same as #:id', { id });
});
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Compare deploy #:id', { id: build.id })" :description="`${build.repository} → ${build.website}`">
            <template #actions><AcmeBtn :to="`/projects/${project.id}/deploy/builds/${build.id}`" icon="chevronLeft">{{ t('Back to deploy') }}</AcmeBtn></template>
        </ProjectHeader>
        <div class="space-y-6">
            <AcmeEmptyCard v-if="comparison === null || baseline === null" icon="layers" :title="t('Nothing to compare with yet')" :description="t('This repository has no other deploys. Compare becomes useful after the next one.')" />
            <template v-else>
                <AcmeCard>
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="text-sm font-semibold text-ink">#{{ build.id }}</span>
                        <span class="text-sm text-muted">{{ t('compared with') }}</span>
                        <SelectField id="compare-with" v-model="chosen" name="with" :label="t('Compare with')" :options="candidates" class="min-w-64 [&_label]:sr-only" />
                    </div>
                </AcmeCard>
                <div class="grid gap-4 md:grid-cols-3">
                    <StatCard :label="t('Timing')" :value="labels.duration(build.seconds)" :description="timing" />
                    <StatCard :label="t('Code')" :value="baseline.revision === build.revision ? t('Same commit') : t('Different commits')">
                        <a v-if="comparison.compareUrl" :href="comparison.compareUrl" target="_blank" rel="noopener" class="mt-1 inline-block text-xs text-ink underline">{{ t('View code changes') }}</a>
                        <p v-else class="mt-1 text-xs text-muted">{{ t('No commit range to show') }}</p>
                    </StatCard>
                    <StatCard :label="t('Settings')" :value="comparison.snapshotsAvailable ? t(':count changed', { count: comparison.changes.length }) : '—'" :description="comparison.snapshotsAvailable ? t('Between the two environment snapshots') : t('One of them didn’t record its environment')" />
                </div>
                <AcmeCard :title="t('Side by side')" :padded="false">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[32rem] text-sm">
                            <caption class="sr-only">{{ t('Side by side') }}</caption>
                            <thead><tr class="border-b border-line text-left text-xs text-muted"><th scope="col" class="px-5 py-2.5 font-medium"><span class="sr-only">{{ t('Measure') }}</span></th><th scope="col" class="px-5 py-2.5 font-medium">#{{ build.id }}</th><th scope="col" class="px-5 py-2.5 font-medium">{{ t('#:id (baseline)', { id: baseline.id }) }}</th></tr></thead>
                            <tbody class="divide-y divide-line">
                                <tr v-for="row in rows" :key="row.label" :class="row.before !== row.after && 'bg-amber-500/[.04]'">
                                    <th scope="row" class="px-5 py-3 text-left font-normal text-muted">{{ row.label }}</th>
                                    <td :class="['px-5 py-3 text-ink', row.label === t('Commit') && 'font-mono']">{{ row.after }}</td>
                                    <td :class="['px-5 py-3 text-muted', row.label === t('Commit') && 'font-mono']">{{ row.before }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </AcmeCard>
                <template v-if="comparison.snapshotsAvailable">
                    <AcmeAlert v-if="comparison.changes.length === 0" tone="info">{{ t('Both deploys ran with the same environment settings, variables and workers.') }}</AcmeAlert>
                    <AcmeCard v-else :title="t('Settings that changed')" :description="t('Variable values, the .env file and resource settings are secret, so only their names are compared.')" :padded="false">
                        <ul class="divide-y divide-line text-sm">
                            <li v-for="(change, index) in comparison.changes" :key="index" class="flex flex-wrap items-center gap-3 px-5 py-3 sm:px-6">
                                <AcmeBadge>{{ areas[change.area] ?? change.area }}</AcmeBadge>
                                <span class="flex-1 font-mono text-xs text-ink">{{ change.name }}</span>
                                <AcmeBadge :tone="kinds[change.kind].tone">{{ kinds[change.kind].label }}</AcmeBadge>
                                <span v-if="change.kind === 'changed'" class="max-w-xs break-all font-mono text-xs text-muted">{{ change.from ?? t('hidden') }} → {{ change.to ?? t('hidden') }}</span>
                            </li>
                        </ul>
                    </AcmeCard>
                </template>
            </template>
        </div>
    </div>
</template>
