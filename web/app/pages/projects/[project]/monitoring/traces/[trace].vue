<script setup lang="ts">
import type { EventRow } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/**
 * One trace (the Acme theme's trace page): where most of its time went, a waterfall of its spans and events, and the
 * deploy that served it.
 */
definePageMeta({ layout: 'app', service: 'monitoring', tab: 'monitoring/events' });
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
// The span below the root that took the biggest share of the trace, when it took over a third of it.
const slowest = computed(() => {
    const spans = data.value.rows.filter((row) => row.depth > 0 && row.width > 0);
    const top = spans.reduce<(typeof spans)[number] | null>((best, row) => (best === null || row.width > best.width ? row : best), null);
    return top !== null && top.width >= 33 ? top : null;
});
const barClass = (row: EventRow) => (row.hasError ? 'bg-rose-500' : row.id === slowest.value?.id ? 'bg-amber-500' : row.hasWarning ? 'bg-amber-400' : 'bg-sky-500');
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="data.title" :description="t('Trace :id · :duration · :spans spans', { id: data.traceId.slice(0, 16), duration: data.duration, spans: number(data.spans) })">
            <template #actions><AcmeBtn icon="list" :to="`/projects/${project.id}/monitoring/events?trace=${data.traceId}`">{{ t('Events in this trace') }}</AcmeBtn></template>
        </ProjectHeader>
        <div class="space-y-6">
            <AcmeAlert v-if="slowest" tone="warning" :title="t('Most of the time is in “:name”', { name: slowest.name })">
                {{ t('It took :duration, about :share% of the trace.', { duration: slowest.duration, share: Math.round(slowest.width) }) }}
            </AcmeAlert>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <AcmeStat :label="t('Duration')" :value="data.duration" icon="clock" />
                <AcmeStat :label="t('Spans')" :value="number(data.spans)" icon="branch" />
                <AcmeStat :label="t('Other events')" :value="number(data.events)" icon="list" />
                <AcmeStat :label="t('Errors')" :value="number(data.errors)" icon="flame" :tone="data.errors > 0 ? 'red' : 'gray'" />
            </div>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-2xl border border-line bg-surface p-4 text-sm shadow-card">
                <AcmeIcon name="rocket" :size="16" class="text-muted" />
                <template v-if="data.deployment">
                    <span class="text-muted">{{ t('Served by') }}</span>
                    <NuxtLink :to="`/projects/${project.id}/monitoring/deployments/${data.deployment.id}`" class="font-mono font-medium text-ink hover:underline">{{ data.deployment.version }}</NuxtLink>
                    <span class="text-muted"><Rich :text="t('deployed to :environment :when', { environment: data.deployment.environment })"><template #when><RelativeTime :at="data.deployment.deployedAt" /></template></Rich></span>
                    <AcmeBtn v-if="data.deployment.buildId" size="sm" class="ml-auto" :to="`/projects/${project.id}/deploy/builds/${data.deployment.buildId}`">{{ t('Deploy #:id', { id: data.deployment.buildId }) }}</AcmeBtn>
                </template>
                <span v-else class="text-muted">{{ t('No deploy recorded before this trace.') }}</span>
            </div>
            <AcmeCard :title="t('Waterfall')" :description="t('Only received spans appear; sampled or missing spans leave gaps. Indentation follows parent spans.')" :padded="false">
                <div class="overflow-x-auto">
                    <div class="min-w-[44rem] px-5 pb-5 sm:px-6">
                        <div class="grid grid-cols-[16rem_1fr_5rem] gap-4 border-b border-line pb-2 text-xs text-muted">
                            <span>{{ t('Operation') }}</span>
                            <span class="flex justify-between"><span>0 ms</span><span>{{ data.duration }}</span></span>
                            <span class="text-right">{{ t('Duration') }}</span>
                        </div>
                        <ol>
                            <li v-for="row in data.rows" :key="row.id" class="border-b border-line/60">
                                <NuxtLink :to="`/projects/${project.id}/monitoring/events/${row.id}`" class="grid grid-cols-[16rem_1fr_5rem] items-center gap-4 py-2 hover:bg-black/[.02] dark:hover:bg-white/[.03]">
                                    <span class="min-w-0" :style="{ paddingLeft: `${Math.min(12, row.depth) * 14}px` }">
                                        <b class="block truncate font-mono text-xs font-medium text-ink">{{ row.name }}</b>
                                        <span class="block truncate text-xs text-muted">{{ [row.service, ...row.notes].join(' · ') }}</span>
                                    </span>
                                    <span class="relative h-6" aria-hidden="true">
                                        <span class="absolute top-1 h-4 rounded" :class="barClass(row)" :style="{ left: `${row.left}%`, width: `max(3px, ${row.width}%)` }" />
                                    </span>
                                    <span class="text-right text-xs tabular-nums text-muted">{{ row.duration }}</span>
                                </NuxtLink>
                            </li>
                        </ol>
                    </div>
                </div>
            </AcmeCard>
        </div>
    </div>
</template>
