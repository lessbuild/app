<script setup lang="ts">
import type { ServerLiveStatus, ServerPage } from '~/types/infrastructure';

/**
 * One server (the Acme theme's server page): provisioning followed live, then tabs for the overview (resources,
 * details, websites, alerts and diagnostics), processes and cron, network, services, the database (recovery and
 * replicas), logs and settings. Running a command and opening a terminal sit in the header.
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
    ...(active.value && data.value.canRunCommands ? { processes: t('Processes & cron'), network: t('Network'), services: t('Services') } : {}),
    ...(database.value ? { database: t('Database') } : {}),
    logs: t('Logs'),
    ...(data.value.canManage ? { settings: t('Settings') } : {}),
}));
// Older links name the sections the tabs are now grouped into.
const aliases: Record<string, string> = { alerts: 'overview', diagnostics: 'overview', cron: 'processes', firewall: 'network', recovery: 'database', replicas: 'database' };
const tab = computed(() => {
    const asked = typeof route.query.tab === 'string' ? route.query.tab : route.query.log ? 'logs' : 'overview';
    const requested = aliases[asked] ?? asked;
    return requested in tabs.value ? requested : 'overview';
});
const live = ref<ServerLiveStatus | null>(null);
const stage = computed(() => live.value?.stage ?? server.value.stage);
const finalStage = computed(() => live.value?.final_stage ?? server.value.finalStage);
const step = computed(() => live.value?.step ?? server.value.step);
const waitingReason = computed(() => (live.value ? live.value.reason : server.value.status === 'waiting_for_ip' ? server.value.error : null));
const status = computed(() => live.value?.status ?? server.value.status);
const description = computed(() => [server.value.typeLabel, server.value.provider ?? t('Imported'), server.value.region].filter(Boolean).join(' · '));
const project = computed(() => data.value.overview.project.id);
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

/** Stop following provisioning. */
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
    <div>
        <ProjectHeader :overview="data.overview" :title="server.label" :description="description">
            <template #actions>
                <template v-if="active && data.canRunCommands">
                    <FormDialog
                        id="run-command"
                        :title="t('Run a command on :server', { server: server.label })"
                        :description="t('Runs as root over SSH. You’ll see its output on the commands page as soon as it finishes.')"
                        :action="`${base}/commands`"
                        :submit="t('Run as root')"
                    >
                        <template #trigger="{ open }"><AcmeBtn icon="terminal" @click="open">{{ t('Run a command') }}</AcmeBtn></template>
                        <TextareaField id="run-command-text" name="command" :label="t('Command')" rows="3" maxlength="4096" class="font-mono" placeholder="systemctl status caddy --no-pager" required autofocus />
                    </FormDialog>
                    <AcmeBtn :to="`/projects/${project}/infrastructure/servers/${server.id}/commands`" icon="clock">{{ t('Command history') }}</AcmeBtn>
                </template>
                <ApiForm v-if="active && data.canOpenTerminal && server.hasHostKey" :action="`${base}/terminal`">
                    <input type="hidden" name="columns" value="120">
                    <input type="hidden" name="rows" value="32">
                    <SubmitButton variant="secondary">{{ t('Open terminal') }}</SubmitButton>
                </ApiForm>
            </template>
        </ProjectHeader>
        <div class="space-y-6">
            <OneTimeSecrets
                :labels="{ root: t('Root password'), mysql: t('MySQL root password') }"
                :title="t('Server passwords')"
                :description="t('This is the only time they’re shown. You sign in with the server’s SSH key; keep these for the console and MySQL.')"
            />

            <section v-if="server.provisioning" class="rounded-2xl border border-sky-500/30 bg-sky-500/[.05] p-5" aria-busy="true">
                <p class="flex flex-wrap items-center gap-2 font-semibold text-ink" role="status" aria-live="polite">
                    <span class="size-2 animate-pulse rounded-full bg-sky-500" aria-hidden="true" />
                    <ServerStatusBadge :status="status" />
                    {{ t('Stage :stage of :final', { stage, final: finalStage }) }}
                </p>
                <p class="mt-1 text-sm text-muted">
                    <template v-if="step">{{ step }}. </template>{{ t('This usually takes about ten minutes; resources, alerts and diagnostics appear once the server is active.') }}
                </p>
                <p v-if="status === 'waiting_for_ip'" class="mt-2 text-sm text-muted">
                    {{ t('Waiting for the provider to start the server and give it an address, then for SSH to answer. This usually takes a minute or two; we keep checking for up to twenty minutes.') }}
                    <span v-if="waitingReason" class="mt-1 block">{{ t('Latest check: :reason', { reason: waitingReason }) }}</span>
                </p>
                <AcmeProgress :value="finalStage > 0 ? (stage / finalStage) * 100 : 0" :label="t('Provisioning progress')" class="mt-4" />
            </section>
            <section v-else-if="server.status === 'failed'" class="space-y-3 rounded-2xl border border-rose-500/30 bg-rose-500/[.04] p-5">
                <p class="flex items-center gap-2 font-semibold text-ink"><ServerStatusBadge :status="status" />{{ server.error }}</p>
                <div v-if="data.canManage" class="flex flex-wrap gap-2">
                    <ApiForm v-if="server.failurePhase === 'initialization'" :action="`${base}/initialization/retry`"><SubmitButton variant="secondary">{{ t('Retry initialisation') }}</SubmitButton></ApiForm>
                    <ApiForm v-else-if="server.failurePhase === 'remote'" :action="`${base}/provisioning/retry`"><SubmitButton variant="secondary">{{ t('Retry provisioning') }}</SubmitButton></ApiForm>
                    <p v-else class="text-sm text-muted">{{ t('Creation failed and nothing was left running. Delete this server and try again.') }}</p>
                </div>
            </section>

            <PageTabs :tabs="tabs" :current="tab" :label="t('Server sections')" />

            <ServerOverview v-if="tab === 'overview'" :page="data" :base="base" />
            <div v-else-if="tab === 'processes'" class="space-y-6">
                <ServerProcesses :page="data" :base="base" />
                <ServerCron :page="data" :base="base" />
            </div>
            <ServerFirewall v-else-if="tab === 'network'" :page="data" :base="base" />
            <ServerServices v-else-if="tab === 'services'" :page="data" :base="base" />
            <div v-else-if="tab === 'database'" class="space-y-6">
                <ServerRecovery :page="data" :base="base" />
                <ServerReplicas :page="data" :base="base" />
            </div>
            <ServerLogs v-else-if="tab === 'logs'" :page="data" :base="base" />
            <ServerSettings v-else :page="data" :base="base" />
        </div>
    </div>
</template>
