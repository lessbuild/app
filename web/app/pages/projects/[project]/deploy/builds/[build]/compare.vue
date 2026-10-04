<script setup lang="ts">
import type { BuildChange, ComparedBuild, ComparisonPage } from '~/types/deploy';
import type { Tone } from '~/types/ui';

/**
 * A deploy beside another of the same repository (the last good one unless `?with=` picks one): timing, code and the
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
const kinds = computed<Record<BuildChange['kind'], { tone: Tone; label: string }>>(() => ({
    added: { tone: 'success', label: t('Added') },
    removed: { tone: 'danger', label: t('Removed') },
    changed: { tone: 'warning', label: t('Changed') },
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
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Compare deploy #:id', { id: build.id })" :description="`${build.repository} → ${build.website}`">
            <template #actions>
                <UiButton :to="`/projects/${project.id}/deploy/builds/${build.id}`" variant="quiet" size="sm">{{ t('Back to deploy #:id', { id: build.id }) }}</UiButton>
            </template>
        </ProjectHeader>

        <div v-if="candidates.length > 0" class="max-w-xl">
            <SelectField id="compare-with" v-model="chosen" name="with" :label="t('Compare with')" :options="candidates" />
        </div>

        <EmptyState v-if="comparison === null || baseline === null" icon="list" :title="t('Nothing to compare with yet')" :description="t('This repository has no other deploys. Compare becomes useful after the next one.')" />
        <template v-else>
            <div class="grid gap-4 sm:grid-cols-3">
                <StatCard :label="t('Took')" :value="labels.duration(build.seconds)" :description="timing" />
                <StatCard
                    :label="t('Settings changed')"
                    :value="comparison.snapshotsAvailable ? String(comparison.changes.length) : '—'"
                    :description="comparison.snapshotsAvailable ? t('Between the two environment snapshots') : t('One of them didn’t record its environment')"
                />
                <StatCard
                    :label="t('Code')"
                    :value="baseline.revision === build.revision ? t('Same commit') : t('Different commits')"
                    :description="comparison.compareUrl ? t('See the diff at your Git provider') : t('No commit range to show')"
                />
            </div>
            <div v-if="comparison.compareUrl">
                <a :href="comparison.compareUrl" target="_blank" rel="noopener" class="ui-btn ui-btn-secondary">{{ t('View code changes') }} <Icon name="external" class="size-4" /></a>
            </div>

            <DataTable :caption="t('Side by side')">
                <template #head>
                    <tr><th scope="col"><span class="sr-only">{{ t('Measure') }}</span></th><th scope="col">{{ t('#:id (baseline)', { id: baseline.id }) }}</th><th scope="col">{{ t('#:id', { id: build.id }) }}</th></tr>
                </template>
                <tr v-for="row in rows" :key="row.label" :class="{ 'bg-warning-soft/40': row.before !== row.after }">
                    <th scope="row" class="text-left font-semibold">{{ row.label }}</th>
                    <td class="text-muted">{{ row.before }}</td>
                    <td class="text-ink">{{ row.after }}</td>
                </tr>
            </DataTable>

            <template v-if="comparison.snapshotsAvailable">
                <Alert v-if="comparison.changes.length === 0" tone="info" role="status">{{ t('Both deploys ran with the same environment settings, variables and workers.') }}</Alert>
                <template v-else>
                    <DataTable :caption="t('Settings that changed')">
                        <template #head>
                            <tr><th scope="col">{{ t('Where') }}</th><th scope="col">{{ t('Name') }}</th><th scope="col">{{ t('Change') }}</th><th scope="col">{{ t('Before') }}</th><th scope="col">{{ t('After') }}</th></tr>
                        </template>
                        <tr v-for="(change, index) in comparison.changes" :key="index">
                            <td>{{ areas[change.area] ?? change.area }}</td>
                            <td class="font-mono text-xs">{{ change.name }}</td>
                            <td><Badge :tone="kinds[change.kind].tone">{{ kinds[change.kind].label }}</Badge></td>
                            <td class="max-w-xs break-all font-mono text-xs text-muted">{{ change.from ?? (change.kind === 'added' ? '—' : t('hidden')) }}</td>
                            <td class="max-w-xs break-all font-mono text-xs">{{ change.to ?? (change.kind === 'removed' ? '—' : t('hidden')) }}</td>
                        </tr>
                    </DataTable>
                    <p class="text-xs text-muted">{{ t('Variable values, the .env file and resource settings are secret, so only their names are compared.') }}</p>
                </template>
            </template>
        </template>
    </div>
</template>
