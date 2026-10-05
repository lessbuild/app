<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';

/**
 * The project's incidents (the Acme theme's incidents page): how many are open and how fast they're handled, then the
 * open ones, or (`?status=resolved`) those that closed, with planned maintenance a tab away.
 */
definePageMeta({ layout: 'app', service: 'monitoring' });
type Incident = { id: number; title: string; status: string; statusLabel: string; openedAt: string; assignee: string | null; source: string | null; environment: string | null; resolvedAt: string | null };
type Stats = { open: number; resolved: number; acknowledgeSeconds: number | null; resolveSeconds: number | null; last30: number; before30: number };
const { t } = useT();
const labels = useDeployLabels();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview; incidents: Incident[]; status: string; stats: Stats; maintenance: number }>(
    () => `/projects/${route.params.project}/monitoring/incidents`,
    () => ({ status: typeof route.query.status === 'string' ? route.query.status : undefined }),
);
const project = computed(() => data.value.overview.project);
const stats = computed(() => data.value.stats);
const tabs = computed(() => ({ open: t('Open'), resolved: t('Closed'), maintenance: t('Maintenance') }));
const bar: Record<string, string> = { open: 'bg-rose-500', acknowledged: 'bg-amber-400', resolved: 'bg-zinc-300 dark:bg-zinc-600' };
/**
 * How long an incident lasted, or has been open.
 *
 * @param incident The incident.
 */
const lasted = (incident: Incident) => labels.duration(Math.round(((incident.resolvedAt ? Date.parse(incident.resolvedAt) : Date.now()) - Date.parse(incident.openedAt)) / 1000));
let timer: number | undefined;

onMounted(() => (timer = window.setInterval(() => refreshNuxtData(), 10000)));
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Monitoring')" :description="t('Incidents open automatically when a monitor fails or an alert rule fires, and close when things recover.')">
            <template #actions>
                <AcmeBtn icon="calendar" :to="`/projects/${project.id}/monitoring/maintenance`">{{ t('Plan maintenance') }}</AcmeBtn>
            </template>
        </ProjectHeader>
        <div class="space-y-6">
            <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
                <AcmeStat :label="t('Open now')" :value="String(stats.open)" icon="alert" tone="red" tinted />
                <AcmeStat :label="t('Mean time to acknowledge')" :value="labels.duration(stats.acknowledgeSeconds)" :period="t('last 30 days')" icon="clock" />
                <AcmeStat :label="t('Mean time to resolve')" :value="labels.duration(stats.resolveSeconds)" :period="t('last 30 days')" icon="checkCircle" tone="green" />
                <AcmeStat
                    :label="t('Incidents, 30 days')"
                    :value="String(stats.last30)"
                    :delta="stats.last30 === stats.before30 ? undefined : `${stats.last30 > stats.before30 ? '+' : '−'}${Math.abs(stats.last30 - stats.before30)}`"
                    :down="stats.last30 < stats.before30"
                    :period="t('vs the 30 days before')"
                    icon="trending"
                />
            </div>

            <AcmeCard :padded="false">
                <div class="px-5 pt-4 sm:px-6">
                    <nav class="flex gap-6 overflow-x-auto border-b border-line [scrollbar-width:none]" :aria-label="t('Incident status')">
                        <NuxtLink
                            v-for="(title, key) in tabs"
                            :key="key"
                            :to="key === 'maintenance' ? `/projects/${project.id}/monitoring/maintenance` : { query: key === 'open' ? {} : { status: key } }"
                            :class="['-mb-px shrink-0 whitespace-nowrap flex items-center gap-2 border-b-2 pb-3 text-sm font-medium', key === data.status ? 'border-accent text-ink' : 'border-transparent text-muted hover:text-ink']"
                            :aria-current="key === data.status ? 'page' : undefined"
                        >
                            {{ title }}<span class="rounded-full bg-black/[.06] px-1.5 text-xs tabular-nums dark:bg-white/10">{{ key === 'open' ? stats.open : key === 'resolved' ? stats.resolved : data.maintenance }}</span>
                        </NuxtLink>
                    </nav>
                </div>
                <ul v-if="data.incidents.length > 0" class="divide-y divide-line" :aria-label="t('Incidents')">
                    <li v-for="incident in data.incidents" :key="incident.id">
                        <NuxtLink :to="`/projects/${project.id}/monitoring/incidents/${incident.id}`" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-4 hover:bg-black/[.02] sm:px-6 dark:hover:bg-white/[.03]">
                            <span :class="['h-10 w-1 shrink-0 rounded-full', bar[incident.status] ?? 'bg-zinc-400']" aria-hidden="true" />
                            <span class="min-w-0 flex-1">
                                <b class="block font-medium text-ink">#{{ incident.id }} · {{ incident.title }}</b>
                                <span class="block text-xs text-muted">{{ [incident.source, incident.environment].filter(Boolean).join(' · ') }}</span>
                            </span>
                            <span class="text-xs text-muted">{{ incident.assignee ?? t('Unassigned') }}</span>
                            <span class="w-32 text-right text-xs text-muted">
                                <RelativeTime :at="incident.openedAt" /><br>
                                {{ incident.resolvedAt ? t('lasted :time', { time: lasted(incident) }) : t('open :time', { time: lasted(incident) }) }}
                            </span>
                            <AcmeBadge :tone="incident.status === 'open' ? 'red' : incident.status === 'acknowledged' ? 'amber' : 'gray'" dot>{{ incident.statusLabel }}</AcmeBadge>
                        </NuxtLink>
                    </li>
                </ul>
                <div v-else class="p-5 sm:p-6">
                    <AcmeEmptyState
                        icon="checkCircle"
                        :title="data.status === 'open' ? t('Nothing open') : t('No closed incidents yet')"
                        :description="data.status === 'open' ? t('Everything your monitors check is passing, or hasn’t failed enough times in a row to open an incident.') : t('Closed incidents stay here for reference.')"
                    />
                </div>
            </AcmeCard>
        </div>
    </div>
</template>
