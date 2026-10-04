<script setup lang="ts">
import type { MonitorSummary } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/** One monitor: its health and latest observation, connecting a job or queue, and its incidents, runs and checks. */
definePageMeta({ layout: 'app', service: 'monitoring' });
type MonitorPage = {
    overview: ProjectOverview;
    monitor: MonitorSummary & { version: number; archived: boolean; observation: string | null; details: string[]; hasKey: boolean; endpoint: string | null; workersEndpoint: string | null };
    queue: { pending: number | null; failed: number | null; workers: number } | null;
    incidents: Array<{ id: number; title: string; status: string; openedAt: string }>;
    runs: Array<{ id: string; status: string; startedAt: string | null; endedAt: string | null }>;
    checks: Array<{ id: number; scheduledAt: string; status: string; outcome: string | null; reason: string; httpStatus: number | null; durationMs: number | null }>;
    canManage: boolean;
};
const { t, number, dateTime } = useT();
const route = useRoute();
const { data } = await useApi<MonitorPage>(() => `/projects/${route.params.project}/monitoring/monitors/${route.params.monitor}`);
const project = computed(() => data.value.overview.project);
const monitor = computed(() => data.value.monitor);
const base = computed(() => `/api/app/projects/${project.value.id}/monitoring/monitors/${monitor.value.id}`);
const signals = computed(() => ['heartbeat', 'queue'].includes(monitor.value.type));
const secrets = useSecrets();
const words = computed<Record<string, string>>(() => ({
    up: t('Up'), down: t('Down'), completed: t('Completed'), pending: t('Pending'), running: t('Running'), skipped: t('Skipped'), failed: t('Failed'),
    succeeded: t('Succeeded'), started: t('Started'), missed: t('Missed'), timed_out: t('Timed out'),
}));
const example = computed(() => (monitor.value.type === 'heartbeat'
    ? `POST ${monitor.value.endpoint}\nAuthorization: Bearer <heartbeat key>\nContent-Type: application/json\n\n{"run_id":"<fresh UUID for this run>","signal":"start"}`
    : `POST ${monitor.value.endpoint}\n{"snapshot_id":"<UUID>","observed_at":"2026-01-01T00:00:00Z","pending":12,"failed":0}\n\nPOST ${monitor.value.workersEndpoint}\n{"worker_id":"<UUID>","sequence":1,"status":"idle"}`));
let timer: number | undefined;

onMounted(() => (timer = window.setInterval(() => refreshNuxtData(), 15000)));
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="monitor.name" :description="`${monitor.typeLabel} · ${monitor.environment} · ${monitor.target}`">
            <template v-if="data.canManage && !monitor.archived" #actions>
                <UiButton :to="`/projects/${project.id}/monitoring/monitors/${monitor.id}/edit`" size="sm">{{ t('Edit') }}</UiButton>
                <DeleteDialog
                    id="archive-monitor"
                    :title="t('Archive :monitor?', { monitor: monitor.name })"
                    :description="t('It stops checking and any open incident closes as “monitor archived”. Its history is kept.')"
                    :warning="signals ? t('Its key stops working too.') : undefined"
                    :action="base"
                    :submit-label="t('Archive monitor')"
                >
                    <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Archive') }}</UiButton></template>
                    <input type="hidden" name="version" :value="monitor.version">
                </DeleteDialog>
            </template>
        </ProjectHeader>

        <div class="flex flex-wrap items-center gap-3 text-sm text-muted">
            <HealthBadge :health="monitor.health" :label="monitor.healthLabel" />
            <span v-if="monitor.observation">{{ monitor.observation }}</span>
            <span v-if="monitor.checkedAt">· <Rich :text="t('Checked :time')"><template #time><RelativeTime :at="monitor.checkedAt" /></template></Rich></span>
        </div>
        <p v-for="(line, index) in monitor.details" :key="index" class="text-xs text-muted">{{ line }}</p>
        <Alert v-if="monitor.archived" tone="info">{{ t('This monitor is archived. It no longer runs; its history stays here.') }}</Alert>

        <SettingsSection
            v-if="signals && !monitor.archived"
            :title="monitor.type === 'heartbeat' ? t('Connect your job') : t('Connect your queue')"
            :description="monitor.type === 'heartbeat' ? t('Send a start, success or failure signal for each run, with a fresh UUID per run.') : t('A collector posts queue counts; each worker posts its own heartbeat.')"
        >
            <div class="grid gap-4 p-4 sm:p-6">
                <Alert v-if="secrets?.monitor_key" tone="success" role="status">
                    <p class="font-bold">{{ t('Copy this key now. It won’t be shown again.') }}</p>
                    <CodeBlock :code="secrets.monitor_key" class="mt-2 whitespace-pre-wrap break-all" />
                </Alert>
                <p class="text-sm text-muted">{{ monitor.hasKey ? t('A key is set.') : t('No key yet: signals are refused until you create one.') }}</p>
                <div v-if="data.canManage" class="flex flex-wrap gap-2">
                    <ApiForm :action="`${base}/key`">
                        <input type="hidden" name="version" :value="monitor.version">
                        <SubmitButton variant="secondary" size="sm">{{ monitor.hasKey ? t('Replace key') : t('Create key') }}</SubmitButton>
                    </ApiForm>
                    <ApiForm v-if="monitor.hasKey" :action="`${base}/key`" method="DELETE">
                        <input type="hidden" name="version" :value="monitor.version">
                        <SubmitButton variant="quiet" size="sm">{{ t('Revoke key and pause') }}</SubmitButton>
                    </ApiForm>
                </div>
                <CodeBlock class="whitespace-pre-wrap break-all" :code="example" />
                <p class="text-xs text-muted">
                    {{ monitor.type === 'heartbeat'
                        ? t('Then send “success” or “failure” with the same run_id. Up to 100 unfinished runs and 60 signals a minute per monitor.')
                        : t('Both use the queue key as a bearer token. Workers send status idle, busy (with job_id) or stopped, and a higher sequence each time.') }}
                </p>
            </div>
        </SettingsSection>

        <div v-if="data.queue" class="grid gap-4 sm:grid-cols-3">
            <StatCard :label="t('Ready jobs')" :value="data.queue.pending === null ? '—' : number(data.queue.pending)" />
            <StatCard :label="t('Failed jobs')" :value="data.queue.failed === null ? '—' : number(data.queue.failed)" />
            <StatCard :label="t('Workers seen today')" :value="number(data.queue.workers)" />
        </div>

        <section v-if="data.incidents.length > 0" class="ui-card overflow-hidden" aria-labelledby="incidents-heading">
            <h2 id="incidents-heading" class="border-b border-line px-5 py-3 text-sm font-bold text-ink">{{ t('Incidents') }}</h2>
            <ul class="divide-y divide-line">
                <li v-for="incident in data.incidents" :key="incident.id" class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm">
                    <NuxtLink :to="`/projects/${project.id}/monitoring/incidents/${incident.id}`" class="font-bold text-ink hover:underline">{{ incident.title }}</NuxtLink>
                    <span class="text-xs text-muted"><RelativeTime :at="incident.openedAt" /></span>
                </li>
            </ul>
        </section>

        <DataTable v-if="monitor.type === 'heartbeat'" :caption="t('Recent runs')">
            <template #head>
                <tr><th scope="col">{{ t('Run') }}</th><th scope="col">{{ t('Status') }}</th><th scope="col">{{ t('Started') }}</th><th scope="col">{{ t('Finished or deadline') }}</th></tr>
            </template>
            <tr v-for="run in data.runs" :key="run.id">
                <td><code class="text-xs">{{ run.id }}</code></td>
                <td>{{ words[run.status] ?? run.status }}</td>
                <td class="whitespace-nowrap">{{ run.startedAt ? dateTime(run.startedAt) : t('Completion only') }}</td>
                <td class="whitespace-nowrap">{{ run.endedAt ? dateTime(run.endedAt) : '—' }}</td>
            </tr>
            <tr v-if="data.runs.length === 0"><td colspan="4" class="py-8 text-center text-muted">{{ t('No signals yet.') }}</td></tr>
        </DataTable>

        <DataTable :caption="t('Recent checks')">
            <template #head>
                <tr><th scope="col">{{ t('When') }}</th><th scope="col">{{ t('Result') }}</th><th scope="col">{{ t('Details') }}</th><th scope="col">{{ t('Duration') }}</th></tr>
            </template>
            <tr v-for="check in data.checks" :key="check.id">
                <td class="whitespace-nowrap">{{ dateTime(check.scheduledAt) }}</td>
                <td>
                    <Badge v-if="check.status !== 'completed'">{{ words[check.status] ?? check.status }}</Badge>
                    <Badge v-else :tone="check.outcome === 'up' ? 'success' : check.outcome === 'down' ? 'danger' : 'neutral'">{{ words[check.outcome ?? ''] ?? check.outcome }}</Badge>
                </td>
                <td class="text-muted">{{ check.reason }}<template v-if="check.httpStatus"> · HTTP {{ check.httpStatus }}</template></td>
                <td class="whitespace-nowrap">{{ check.durationMs === null ? '—' : `${number(check.durationMs)} ms` }}</td>
            </tr>
            <tr v-if="data.checks.length === 0"><td colspan="4" class="py-8 text-center text-muted">{{ t('No checks yet. The first one runs within a minute.') }}</td></tr>
        </DataTable>
    </div>
</template>
