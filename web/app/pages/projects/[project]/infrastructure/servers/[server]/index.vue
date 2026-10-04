<script setup lang="ts">
import type { ServerLiveStatus, ServerPage } from '~/types/infrastructure';

/**
 * One server: provisioning (followed live), resources and the tabs for alerts, diagnostics, database recovery and
 * replicas, cron jobs, processes, firewall, services, logs and settings.
 */
definePageMeta({ layout: 'app', service: 'infrastructure' });
const { t } = useT();
const route = useRoute();
const { data } = await useApi<ServerPage>(
    () => `/projects/${route.params.project}/infrastructure/servers/${route.params.server}`,
    () => (typeof route.query.log === 'string' ? { log: route.query.log } : {}),
);
const server = computed(() => data.value.server);
const base = computed(() => `/api/app/projects/${data.value.overview.project.id}/infrastructure/servers/${server.value.id}`);
const active = computed(() => server.value.status === 'active');
const database = computed(() => active.value && data.value.recovery !== null);
const tabs = computed<Record<string, string>>(() => ({
    overview: t('Overview'),
    ...(active.value ? { alerts: t('Alerts'), diagnostics: t('Diagnostics') } : {}),
    ...(database.value ? { recovery: t('Recovery'), replicas: t('Replicas') } : {}),
    ...(active.value && data.value.canRunCommands ? { cron: t('Cron jobs'), processes: t('Processes'), firewall: t('Firewall'), services: t('Services') } : {}),
    logs: t('Logs'),
    ...(data.value.canManage ? { settings: t('Settings') } : {}),
}));
const tab = computed(() => {
    const requested = typeof route.query.tab === 'string' ? route.query.tab : route.query.log ? 'logs' : 'overview';
    return requested in tabs.value ? requested : 'overview';
});
const live = ref<ServerLiveStatus | null>(null);
const stage = computed(() => live.value?.stage ?? server.value.stage);
const finalStage = computed(() => live.value?.final_stage ?? server.value.finalStage);
const step = computed(() => live.value?.step ?? server.value.step);
const waitingReason = computed(() => (live.value ? live.value.reason : server.value.status === 'waiting_for_ip' ? server.value.error : null));
const status = computed(() => live.value?.status ?? server.value.status);
const description = computed(() => [server.value.typeLabel, server.value.ip, server.value.provider ? `${server.value.provider}${server.value.region ? ` ${server.value.region}` : ''}` : t('Imported')].filter(Boolean).join(' · '));
let timer: number | undefined;

/** Ask how provisioning is going; once it's done, load the whole page again. */
async function poll() {
    try {
        live.value = await send<ServerLiveStatus>('GET', `/projects/${data.value.overview.project.id}/infrastructure/servers/${server.value.id}/status`);
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

watch(() => server.value.provisioning && server.value.id, (following) => {
    stop();
    if (following && import.meta.client) {
        timer = window.setInterval(poll, 5000);
    }
}, { immediate: true });
onBeforeUnmount(stop);
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="server.label" :description="description" />
        <OneTimeSecrets
            :labels="{ root: t('Root password'), mysql: t('MySQL root password') }"
            :title="t('Server passwords')"
            :description="t('This is the only time they’re shown. You sign in with the server’s SSH key; keep these for the console and MySQL.')"
        />

        <section class="ui-card grid gap-4 p-5" :aria-busy="server.provisioning || undefined">
            <div class="flex flex-wrap items-center gap-3">
                <span role="status" aria-live="polite"><ServerStatusBadge :status="status" /></span>
                <template v-if="server.provisioning">
                    <span class="text-sm text-muted">{{ t('Stage :stage of :final', { stage, final: finalStage }) }}</span>
                    <span v-if="step" class="inline-flex items-center gap-2 text-sm font-semibold text-ink" aria-live="polite">
                        <span class="size-2 animate-pulse rounded-full bg-primary" aria-hidden="true" />{{ step }}
                    </span>
                </template>
                <span v-else-if="server.provisionedAt" class="text-sm text-muted"><Rich :text="t('Provisioned :time')"><template #time><RelativeTime :at="server.provisionedAt" /></template></Rich></span>
            </div>
            <ProgressBar v-if="server.provisioning" :value="stage" :max="finalStage" :label="t('Provisioning progress')" />
            <p v-if="server.provisioning && status === 'waiting_for_ip'" class="text-sm text-muted">
                {{ t('Waiting for the provider to start the server and give it an address, then for SSH to answer. This usually takes a minute or two; we keep checking for up to twenty minutes.') }}
                <span v-if="waitingReason" class="mt-1 block">{{ t('Latest check: :reason', { reason: waitingReason }) }}</span>
            </p>
            <template v-if="server.status === 'failed'">
                <Alert tone="danger">{{ server.error }}</Alert>
                <div v-if="data.canManage" class="flex flex-wrap gap-2">
                    <ApiForm v-if="server.failurePhase === 'initialization'" :action="`${base}/initialization/retry`"><SubmitButton variant="secondary">{{ t('Retry initialisation') }}</SubmitButton></ApiForm>
                    <ApiForm v-else-if="server.failurePhase === 'remote'" :action="`${base}/provisioning/retry`"><SubmitButton variant="secondary">{{ t('Retry provisioning') }}</SubmitButton></ApiForm>
                    <p v-else class="text-sm text-muted">{{ t('Creation failed and nothing was left running. Delete this server and try again.') }}</p>
                </div>
            </template>
            <dl class="grid gap-4 text-sm sm:grid-cols-3">
                <div><dt class="text-xs text-muted">{{ t('SSH') }}</dt><dd class="mt-1 font-mono">root@{{ live?.public_ip ?? server.ip ?? '—' }}:{{ server.sshPort }}</dd></div>
                <div><dt class="text-xs text-muted">{{ t('Host key') }}</dt><dd class="mt-1 break-all font-mono text-xs">{{ live?.host_key ?? server.hostFingerprint ?? '—' }}</dd></div>
                <div><dt class="text-xs text-muted">{{ t('Size · image') }}</dt><dd class="mt-1">{{ server.size ?? '—' }} · {{ server.image ?? '—' }}</dd></div>
            </dl>
        </section>

        <PageTabs :tabs="tabs" :current="tab" :label="t('Server sections')" />

        <ServerOverview v-if="tab === 'overview'" :page="data" :base="base" />
        <ServerAlerts v-else-if="tab === 'alerts'" :page="data" :base="base" />
        <ServerDiagnostics v-else-if="tab === 'diagnostics'" :page="data" :base="base" />
        <ServerRecovery v-else-if="tab === 'recovery'" :page="data" :base="base" />
        <ServerReplicas v-else-if="tab === 'replicas'" :page="data" :base="base" />
        <ServerCron v-else-if="tab === 'cron'" :page="data" :base="base" />
        <ServerProcesses v-else-if="tab === 'processes'" :page="data" :base="base" />
        <ServerFirewall v-else-if="tab === 'firewall'" :page="data" :base="base" />
        <ServerServices v-else-if="tab === 'services'" :page="data" :base="base" />
        <ServerLogs v-else-if="tab === 'logs'" :page="data" :base="base" />
        <ServerSettings v-else :page="data" :base="base" />
    </div>
</template>
