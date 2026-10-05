<script setup lang="ts">
import type { EventRow } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/** One trace as a timeline of its spans and events, and the deploy that served it. */
definePageMeta({ layout: 'app', service: 'monitoring' });
type TracePage = {
    overview: ProjectOverview;
    traceId: string;
    title: string;
    duration: string;
    spans: number;
    events: number;
    errors: number;
    rows: Array<EventRow & { left: number; width: number; depth: number; notes: string[] }>;
    deployment: { id: number; version: string; environment: string; deployedAt: string; buildId: number | null } | null;
};
const { t, number } = useT();
const route = useRoute();
const { data } = await useApi<TracePage>(() => `/projects/${route.params.project}/monitoring/traces/${route.params.trace}`);
const project = computed(() => data.value.overview.project);
const barClass = (row: EventRow) => (row.hasError ? 'bg-[var(--ui-danger)]' : row.hasWarning ? 'bg-[var(--ui-warning)]' : 'bg-[var(--ui-chart-1)]');
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="data.title" :description="t('Trace :id', { id: data.traceId })" />
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard :label="t('Duration')" :value="data.duration" />
            <StatCard :label="t('Spans')" :value="number(data.spans)" />
            <StatCard :label="t('Other events')" :value="number(data.events)" />
            <StatCard :label="t('Errors')" :value="number(data.errors)" />
        </div>
        <div class="ui-card flex flex-wrap items-center gap-x-3 gap-y-1 p-4 text-sm">
            <template v-if="data.deployment">
                <span class="text-muted">{{ t('Served by') }}</span>
                <NuxtLink :to="`/projects/${project.id}/monitoring/deployments/${data.deployment.id}`" class="font-bold text-primary hover:underline">{{ data.deployment.version }}</NuxtLink>
                <span class="text-muted"><Rich :text="t('deployed to :environment :when', { environment: data.deployment.environment })"><template #when><RelativeTime :at="data.deployment.deployedAt" /></template></Rich></span>
                <NuxtLink v-if="data.deployment.buildId" :to="`/projects/${project.id}/deploy/builds/${data.deployment.buildId}`" class="text-primary hover:underline">{{ t('Deploy #:id', { id: data.deployment.buildId }) }}</NuxtLink>
            </template>
            <span v-else class="text-muted">{{ t('No deploy recorded before this trace.') }}</span>
        </div>
        <section class="ui-card overflow-x-auto px-5 pb-5 sm:px-6 sm:pb-6" :aria-label="t('Trace timeline')">
            <div class="min-w-[640px]">
                <div class="mb-2 grid grid-cols-[minmax(200px,1fr)_minmax(260px,1.6fr)_96px] gap-4 text-xs text-muted">
                    <span>{{ t('Operation') }}</span>
                    <span class="flex justify-between"><span>0 ms</span><span>{{ data.duration }}</span></span>
                    <span class="text-right">{{ t('Duration') }}</span>
                </div>
                <ol class="grid gap-1">
                    <li v-for="row in data.rows" :key="row.id">
                        <NuxtLink :to="`/projects/${project.id}/monitoring/events/${row.id}`" class="grid grid-cols-[minmax(200px,1fr)_minmax(260px,1.6fr)_96px] items-center gap-4 rounded-control px-2 py-2 hover:bg-surface-muted">
                            <span class="min-w-0" :style="{ paddingLeft: `${Math.min(12, row.depth) * 14}px` }">
                                <span class="block truncate text-sm font-semibold text-ink">{{ row.name }}</span>
                                <span class="block truncate text-xs text-muted">{{ [row.service, ...row.notes].join(' · ') }}</span>
                            </span>
                            <span class="relative h-3 rounded-full bg-surface-muted" aria-hidden="true">
                                <span class="absolute top-0 h-3 rounded-full" :class="barClass(row)" :style="{ left: `${row.left}%`, width: `max(2px, ${row.width}%)` }" />
                            </span>
                            <span class="text-right text-xs tabular-nums text-muted">{{ row.duration }}</span>
                        </NuxtLink>
                    </li>
                </ol>
            </div>
        </section>
        <p class="text-xs text-muted">{{ t('Only received spans appear; sampled or missing spans leave gaps. Indentation follows parent spans.') }}</p>
    </div>
</template>
