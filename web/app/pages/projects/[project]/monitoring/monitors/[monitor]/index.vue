<script setup lang="ts">
import type { MonitorActivity, MonitorSummary } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/**
 * One monitor (the Acme theme's monitor page): its status, uptime, response time and incidents, the last day as a strip,
 * connecting a job or queue, and its runs, checks and incidents. It can be paused from here.
 */
definePageMeta({ layout: 'app', service: 'monitoring' });
type MonitorPage = {
    overview: ProjectOverview;
    monitor: MonitorSummary & MonitorActivity & { version: number; archived: boolean; enabled: boolean; observation: string | null; details: string[]; hasKey: boolean; endpoint: string | null; workersEndpoint: string | null };
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
    open: t('Open'), acknowledged: t('Acknowledged'), resolved: t('Resolved'),
}));
const example = computed(() => (monitor.value.type === 'heartbeat'
    ? `POST ${monitor.value.endpoint}\nAuthorization: Bearer <heartbeat key>\nContent-Type: application/json\n\n{"run_id":"<fresh UUID for this run>","signal":"start"}`
    : `POST ${monitor.value.endpoint}\n{"snapshot_id":"<UUID>","observed_at":"2026-01-01T00:00:00Z","pending":12,"failed":0}\n\nPOST ${monitor.value.workersEndpoint}\n{"worker_id":"<UUID>","sequence":1,"status":"idle"}`));
const more = computed(() => [{ label: t('Archive'), icon: 'archive', danger: true, onSelect: () => navigateTo({ query: { ...route.query, dialog: 'archive-monitor' } }) }]);
let timer: number | undefined;

onMounted(() => (timer = window.setInterval(() => refreshNuxtData(), 15000)));
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="monitor.name" :description="`${monitor.typeLabel} · ${monitor.target} · ${monitor.environment}`">
            <template v-if="data.canManage && !monitor.archived" #actions>
                <ApiForm :action="`${base}/enabled`" method="PUT">
                    <input type="hidden" name="enabled" :value="monitor.enabled ? 0 : 1">
                    <input type="hidden" name="version" :value="monitor.version">
                    <SubmitButton variant="secondary">{{ monitor.enabled ? t('Pause') : t('Resume') }}</SubmitButton>
                </ApiForm>
                <AcmeBtn icon="edit" :to="`/projects/${project.id}/monitoring/monitors/${monitor.id}/edit`">{{ t('Edit') }}</AcmeBtn>
                <AcmeMenu :items="more" :label="t('More')" icon="dots" align="right" />
                <DeleteDialog
                    id="archive-monitor"
                    :title="t('Archive :monitor?', { monitor: monitor.name })"
                    :description="t('It stops checking and any open incident closes as “monitor archived”. Its history is kept.')"
                    :warning="signals ? t('Its key stops working too.') : undefined"
                    :action="base"
                    :submit-label="t('Archive monitor')"
                >
                    <template #trigger><span class="hidden" /></template>
                    <input type="hidden" name="version" :value="monitor.version">
                </DeleteDialog>
            </template>
        </ProjectHeader>

        <div class="space-y-6">
            <AcmeAlert v-if="monitor.archived" tone="info">{{ t('This monitor is archived. It no longer runs; its history stays here.') }}</AcmeAlert>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-2xl border border-line bg-surface p-4 shadow-card">
                    <p class="text-xs text-muted">{{ t('Status') }}</p>
                    <p class="mt-2"><HealthBadge :health="monitor.health" :label="monitor.healthLabel" /></p>
                    <p v-if="monitor.checkedAt" class="mt-2 text-xs text-muted"><Rich :text="t('Checked :time')"><template #time><RelativeTime :at="monitor.checkedAt" /></template></Rich></p>
                </div>
                <div class="rounded-2xl border border-line bg-surface p-4 shadow-card">
                    <p class="text-xs text-muted">{{ t('Uptime, 30 days') }}</p>
                    <p class="mt-2 text-2xl font-semibold tabular-nums text-ink">{{ monitor.uptime === null ? '—' : `${monitor.uptime}%` }}</p>
                </div>
                <div class="rounded-2xl border border-line bg-surface p-4 shadow-card">
                    <p class="text-xs text-muted">{{ t('Response time') }}</p>
                    <p class="mt-2 text-2xl font-semibold tabular-nums text-ink">{{ monitor.latencyMs === null ? '—' : `${number(monitor.latencyMs)} ms` }}</p>
                </div>
                <div class="rounded-2xl border border-line bg-surface p-4 shadow-card">
                    <p class="text-xs text-muted">{{ t('Incidents, 30 days') }}</p>
                    <p class="mt-2 text-2xl font-semibold tabular-nums text-ink">{{ monitor.incidents }}</p>
                </div>
            </div>
            <div v-if="monitor.observation || monitor.details.length > 0" class="text-sm text-muted">
                <p v-if="monitor.observation">{{ monitor.observation }}</p>
                <p v-for="(line, index) in monitor.details" :key="index" class="text-xs">{{ line }}</p>
            </div>
            <AcmeCard :title="t('Last 24 hours')" :description="t('Each bar is 30 minutes.')">
                <AcmeUptimeBar :days="monitor.strip" :label="t(':monitor, last 24 hours', { monitor: monitor.name })" :step="30" />
            </AcmeCard>

        <AcmeCard
v-if="signals && !monitor.archived"
            :padded="false"
            :title="monitor.type === 'heartbeat' ? t('Connect your job') : t('Connect your queue')"
            :description="monitor.type === 'heartbeat' ? t('Send a start, success or failure signal for each run, with a fresh UUID per run.') : t('A collector posts queue counts; each worker posts its own heartbeat.')"
        >
            <div class="grid gap-4 px-5 pb-5 sm:px-6 sm:pb-6">
                <AcmeAlert v-if="secrets?.monitor_key" tone="success" role="status">
                    <p class="font-bold">{{ t('Copy this key now. It won’t be shown again.') }}</p>
                    <CodeBlock :code="secrets.monitor_key" class="mt-2 whitespace-pre-wrap break-all" />
                </AcmeAlert>
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
        </AcmeCard>

        <div v-if="data.queue" class="grid gap-3 sm:grid-cols-3">
            <AcmeStat :label="t('Ready jobs')" :value="data.queue.pending === null ? '—' : number(data.queue.pending)" icon="layers" tone="blue" />
            <AcmeStat :label="t('Failed jobs')" :value="data.queue.failed === null ? '—' : number(data.queue.failed)" icon="circleX" tone="red" />
            <AcmeStat :label="t('Workers seen today')" :value="number(data.queue.workers)" icon="cpu" tone="green" />
        </div>

        <AcmeCard v-if="monitor.type === 'heartbeat'" :title="t('Recent runs')" :padded="false">
            <DataTable :caption="t('Recent runs')" :framed="false">
                <template #head>
                    <tr><th scope="col">{{ t('Run') }}</th><th scope="col">{{ t('Status') }}</th><th scope="col">{{ t('Started') }}</th><th scope="col">{{ t('Finished or deadline') }}</th></tr>
                </template>
                <tr v-for="run in data.runs" :key="run.id">
                    <td><code class="font-mono text-xs">{{ run.id }}</code></td>
                    <td><AcmeBadge :tone="['succeeded', 'completed'].includes(run.status) ? 'green' : ['failed', 'missed', 'timed_out'].includes(run.status) ? 'red' : 'gray'" dot>{{ words[run.status] ?? run.status }}</AcmeBadge></td>
                    <td class="whitespace-nowrap text-muted">{{ run.startedAt ? dateTime(run.startedAt) : t('Completion only') }}</td>
                    <td class="whitespace-nowrap text-muted">{{ run.endedAt ? dateTime(run.endedAt) : '—' }}</td>
                </tr>
                <tr v-if="data.runs.length === 0"><td colspan="4" class="py-8 text-center text-muted">{{ t('No signals yet.') }}</td></tr>
            </DataTable>
        </AcmeCard>

        <AcmeCard v-if="monitor.type !== 'heartbeat'" :title="t('Recent checks')" :padded="false">
            <DataTable :caption="t('Recent checks')" :framed="false">
                <template #head>
                    <tr><th scope="col">{{ t('When') }}</th><th scope="col">{{ t('Result') }}</th><th scope="col">{{ t('Details') }}</th><th scope="col" class="text-right">{{ t('Duration') }}</th></tr>
                </template>
                <tr v-for="check in data.checks" :key="check.id">
                    <td class="whitespace-nowrap font-mono text-xs">{{ dateTime(check.scheduledAt) }}</td>
                    <td>
                        <AcmeBadge v-if="check.status !== 'completed'" dot>{{ words[check.status] ?? check.status }}</AcmeBadge>
                        <AcmeBadge v-else :tone="check.outcome === 'up' ? 'green' : check.outcome === 'down' ? 'red' : 'gray'" dot>{{ words[check.outcome ?? ''] ?? check.outcome }}</AcmeBadge>
                    </td>
                    <td class="text-muted">{{ check.reason }}<template v-if="check.httpStatus"> · HTTP {{ check.httpStatus }}</template></td>
                    <td class="whitespace-nowrap text-right tabular-nums">{{ check.durationMs === null ? '—' : `${number(check.durationMs)} ms` }}</td>
                </tr>
                <tr v-if="data.checks.length === 0"><td colspan="4" class="py-8 text-center text-muted">{{ t('No checks yet. The first one runs within a minute.') }}</td></tr>
            </DataTable>
        </AcmeCard>

        <AcmeCard :title="t('Incidents')" :padded="false">
            <ul v-if="data.incidents.length > 0" class="divide-y divide-line text-sm">
                <li v-for="incident in data.incidents" :key="incident.id">
                    <NuxtLink :to="`/projects/${project.id}/monitoring/incidents/${incident.id}`" class="flex items-center gap-3 px-5 py-3 hover:bg-black/[.02] sm:px-6 dark:hover:bg-white/[.03]">
                        <AcmeBadge :tone="incident.status === 'resolved' ? 'green' : 'red'" dot>{{ words[incident.status] ?? incident.status }}</AcmeBadge>
                        <span class="flex-1 font-medium text-ink">{{ incident.title }}</span>
                        <span class="text-xs text-muted"><RelativeTime :at="incident.openedAt" /></span>
                    </NuxtLink>
                </li>
            </ul>
            <p v-else class="px-5 pb-5 text-sm text-muted sm:px-6">{{ t('No incidents yet.') }}</p>
        </AcmeCard>
        </div>
    </div>
</template>
