<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';

/** The project's incidents: the open ones, or (`?status=resolved`) those that closed. */
definePageMeta({ layout: 'app', service: 'monitoring' });
type Incident = { id: number; title: string; status: string; statusLabel: string; openedAt: string; assignee: string | null };
const { t } = useT();
const route = useRoute();
const { data } = await useApi<{ overview: ProjectOverview; incidents: Incident[]; status: string }>(
    () => `/projects/${route.params.project}/monitoring/incidents`,
    () => ({ status: typeof route.query.status === 'string' ? route.query.status : undefined }),
);
const project = computed(() => data.value.overview.project);
let timer: number | undefined;

onMounted(() => (timer = window.setInterval(() => refreshNuxtData(), 10000)));
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="t('Incidents')" :description="t('An incident opens when a monitor fails enough checks in a row, and closes when it recovers.')" />
        <nav :aria-label="t('Incident status')" class="flex gap-2">
            <UiButton :to="{ query: {} }" :variant="data.status === 'open' ? 'soft' : 'quiet'" size="sm" :aria-current="data.status === 'open' ? 'page' : undefined">{{ t('Open') }}</UiButton>
            <UiButton :to="{ query: { status: 'resolved' } }" :variant="data.status === 'resolved' ? 'soft' : 'quiet'" size="sm" :aria-current="data.status === 'resolved' ? 'page' : undefined">{{ t('Closed') }}</UiButton>
        </nav>
        <EmptyState
            v-if="data.incidents.length === 0"
            icon="check-circle"
            :title="data.status === 'open' ? t('No open incidents') : t('No closed incidents yet')"
            :description="data.status === 'open' ? t('Everything your monitors check is passing, or hasn’t failed enough times in a row to open an incident.') : t('Closed incidents stay here for reference.')"
        />
        <section v-else class="ui-card overflow-hidden">
            <ul class="divide-y divide-line" :aria-label="t('Incidents')">
                <li v-for="incident in data.incidents" :key="incident.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                    <div class="min-w-0">
                        <NuxtLink :to="`/projects/${project.id}/monitoring/incidents/${incident.id}`" class="font-extrabold text-ink hover:underline">{{ incident.title }}</NuxtLink>
                        <p class="mt-0.5 text-xs text-muted">
                            #{{ incident.id }} · <Rich :text="t('Opened :time')"><template #time><RelativeTime :at="incident.openedAt" /></template></Rich>
                            <template v-if="incident.assignee"> · {{ t('Assigned to :name', { name: incident.assignee }) }}</template>
                        </p>
                    </div>
                    <Badge :tone="incident.status === 'open' ? 'danger' : incident.status === 'acknowledged' ? 'warning' : 'neutral'">{{ incident.statusLabel }}</Badge>
                </li>
            </ul>
        </section>
    </div>
</template>
