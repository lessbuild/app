<script setup lang="ts">
import type { EventRow } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/** One event: what it was, its release, trace and issue, and its (redacted) attributes and payload. */
definePageMeta({ layout: 'app', service: 'monitoring' });
type EventPage = {
    overview: ProjectOverview;
    event: EventRow & { route: string | null; severityLabel: string };
    release: { id: number; version: string } | null;
    attributes: string;
    payload: string;
};
const { t, dateTime } = useT();
const route = useRoute();
const { data } = await useApi<EventPage>(() => `/projects/${route.params.project}/monitoring/events/${route.params.event}`);
const project = computed(() => data.value.overview.project);
const event = computed(() => data.value.event);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="event.name" :description="[event.typeLabel, event.environment, dateTime(event.occurredAt)].filter(Boolean).join(' · ')" />
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard :label="t('Severity')" :value="event.severityLabel" />
            <StatCard :label="t('Duration')" :value="event.duration" />
            <StatCard :label="t('Status')" :value="event.statusCode === null ? '—' : String(event.statusCode)" />
            <StatCard :label="t('Service')" :value="event.service" />
        </div>
        <dl v-if="event.route || data.release || event.traceId || event.issueId" class="ui-card grid gap-2 p-5 text-sm">
            <div v-if="event.route" class="flex flex-wrap gap-2"><dt class="text-muted">{{ t('Route') }}</dt><dd class="break-all">{{ event.route }}</dd></div>
            <div v-if="data.release" class="flex flex-wrap gap-2">
                <dt class="text-muted">{{ t('Release') }}</dt>
                <dd><NuxtLink :to="`/projects/${project.id}/monitoring/releases/${data.release.id}`" class="text-primary hover:underline">{{ data.release.version }}</NuxtLink></dd>
            </div>
            <div v-if="event.traceId" class="flex flex-wrap gap-2">
                <dt class="text-muted">{{ t('Trace') }}</dt>
                <dd><NuxtLink :to="`/projects/${project.id}/monitoring/traces/${event.traceId}`" class="break-all text-primary hover:underline">{{ event.traceId }}</NuxtLink></dd>
            </div>
            <div v-if="event.issueId"><NuxtLink :to="`/projects/${project.id}/monitoring/issues/${event.issueId}`" class="text-primary hover:underline">{{ t('View the issue') }}</NuxtLink></div>
        </dl>
        <section class="ui-card p-5" aria-labelledby="event-attributes">
            <h2 id="event-attributes" class="text-sm font-bold text-ink">{{ t('Attributes') }}</h2>
            <CodeBlock :code="data.attributes" class="mt-3 max-h-96 overflow-auto text-xs" />
        </section>
        <section class="ui-card p-5" aria-labelledby="event-payload">
            <h2 id="event-payload" class="text-sm font-bold text-ink">{{ t('Payload') }}</h2>
            <p class="mt-1 text-xs text-muted">{{ t('Sensitive values are redacted when stored and again when shown.') }}</p>
            <CodeBlock :code="data.payload" class="mt-3 max-h-[32rem] overflow-auto text-xs" />
        </section>
    </div>
</template>
