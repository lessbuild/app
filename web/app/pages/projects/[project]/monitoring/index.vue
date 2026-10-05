<script setup lang="ts">
import type { MonitorActivity, MonitorSummary } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';

/**
 * Monitoring's front page (the Acme theme's monitors page): how many monitors are up, degraded, down or paused, every
 * monitor with its last day as a strip, its uptime and response time, and the third-party services it follows.
 */
definePageMeta({ layout: 'app', service: 'monitoring' });
type ThirdParty = { id: number; name: string; url: string; summary: string | null; affected: string[]; checkedAt: string | null; error: string | null; tone: 'success' | 'warning' | 'danger' | 'neutral' | 'info'; label: string };
type MonitorsPage = { overview: ProjectOverview; monitors: Array<MonitorSummary & MonitorActivity>; thirdParty: ThirdParty[]; providers: Record<string, string>; canManage: boolean };
const { t } = useT();
const route = useRoute();
const { data } = await useApi<MonitorsPage>(() => `/projects/${route.params.project}/monitoring`);
const project = computed(() => data.value.overview.project);
const provider = ref<string | null>(Object.keys(data.value.providers)[0] ?? 'custom');
const providers = computed(() => [...Object.entries(data.value.providers).map(([value, label]) => ({ value, label })), { value: 'custom', label: t('Another status page…') }]);
const filter = ref<string | number>('all');
const filters = computed(() => [
    { value: 'all', label: t('All') }, { value: 'problems', label: t('Problems') }, { value: 'http', label: 'HTTP' },
    { value: 'heartbeat', label: t('Heartbeat') }, { value: 'queue', label: t('Queue') },
]);
const problem = (health: string) => ['Down', 'Degraded', 'Unknown'].includes(health);
const rows = computed(() => data.value.monitors.filter((monitor) => filter.value === 'all' || (filter.value === 'problems' ? problem(monitor.health) : monitor.type === filter.value)));
const count = (health: string) => data.value.monitors.filter((monitor) => monitor.health === health).length;
const dot: Record<string, string> = { Up: 'bg-emerald-500', Degraded: 'bg-amber-500', Down: 'bg-rose-500', Paused: 'bg-zinc-400', Unknown: 'bg-amber-500', Pending: 'bg-zinc-400' };
let timer: number | undefined;

// The list keeps itself current, as checks run every few minutes.
onMounted(() => (timer = window.setInterval(() => refreshNuxtData(), 15000)));
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="t('Monitoring')" :description="t('Uptime, DNS, TLS and TCP checks, plus heartbeats from your jobs and signals from your queues.')">
            <template #actions>
                <AcmeBtn :to="`/projects/${project.id}/monitoring/maintenance`" icon="calendar">{{ t('Maintenance') }}</AcmeBtn>
                <AcmeBtn v-if="data.canManage" variant="primary" :to="`/projects/${project.id}/monitoring/monitors/create`" icon="plus">{{ t('Add a monitor') }}</AcmeBtn>
            </template>
        </ProjectHeader>

        <div class="space-y-6">
            <AcmeEmptyState
                v-if="data.monitors.length === 0"
                icon="checkCircle"
                :title="t('Add your first monitor')"
                :description="t('Check a URL every few minutes, watch a certificate or DNS record, or have a cron job report in. When something breaks, an incident opens and your alert destinations hear about it.')"
            >
                <AcmeBtn v-if="data.canManage" variant="primary" icon="plus" :to="`/projects/${project.id}/monitoring/monitors/create`">{{ t('Add a monitor') }}</AcmeBtn>
            </AcmeEmptyState>
            <template v-else>
                <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
                    <AcmeStat :label="t('Up')" :value="String(count('Up'))" icon="checkCircle" tone="green" tinted />
                    <AcmeStat :label="t('Degraded')" :value="String(count('Degraded') + count('Unknown'))" icon="alert" tone="amber" tinted />
                    <AcmeStat :label="t('Down')" :value="String(count('Down'))" icon="circleX" tone="red" tinted />
                    <AcmeStat :label="t('Paused')" :value="String(count('Paused'))" icon="pause" tone="gray" tinted />
                </div>

                <AcmeCard :padded="false">
                    <div class="flex flex-wrap items-center justify-between gap-3 px-5 pt-5 sm:px-6">
                        <h2 class="font-semibold text-ink">{{ t('Monitors') }}</h2>
                        <AcmeSegmented v-model="filter" :options="filters" :label="t('Filter monitors')" size="sm" />
                    </div>
                    <ul class="mt-4 divide-y divide-line border-t border-line" :aria-label="t('Monitors')">
                        <li v-for="monitor in rows" :key="monitor.id">
                            <NuxtLink :to="`/projects/${project.id}/monitoring/monitors/${monitor.id}`" class="grid gap-3 px-5 py-4 hover:bg-black/[.02] sm:px-6 lg:grid-cols-[1.2fr_1fr_9rem_7rem] lg:items-center dark:hover:bg-white/[.03]">
                                <span class="flex min-w-0 items-center gap-3">
                                    <span :class="['size-2.5 shrink-0 rounded-full', dot[monitor.health] ?? 'bg-zinc-400']" aria-hidden="true" />
                                    <span class="min-w-0">
                                        <b class="block truncate text-sm font-medium text-ink">{{ monitor.name }}</b>
                                        <span class="block truncate text-xs text-muted">{{ monitor.typeLabel }} · {{ monitor.environment }} · {{ monitor.target }}</span>
                                    </span>
                                </span>
                                <AcmeUptimeBar :days="monitor.strip" :label="t(':monitor, last 24 hours', { monitor: monitor.name })" :step="30" compact />
                                <span class="text-sm tabular-nums">
                                    <b class="font-medium text-ink">{{ monitor.uptime === null ? '—' : `${monitor.uptime}%` }}</b>
                                    <span v-if="monitor.latencyMs !== null" class="text-xs text-muted"> · {{ monitor.latencyMs }} ms</span>
                                </span>
                                <span class="flex items-center justify-end gap-2 text-right text-xs text-muted">
                                    <HealthBadge v-if="monitor.health !== 'Up'" :health="monitor.health" :label="monitor.healthLabel" />
                                    <RelativeTime v-else-if="monitor.checkedAt" :at="monitor.checkedAt" />
                                </span>
                            </NuxtLink>
                        </li>
                    </ul>
                    <p v-if="rows.length === 0" class="px-5 py-5 text-sm text-muted sm:px-6">{{ t('No monitors match.') }}</p>
                </AcmeCard>
            </template>

            <AcmeCard id="third-party" :title="t('Services you depend on')" :description="t('The public status of the services your apps rely on, checked every five minutes, beside your own monitors.')">
                <template v-if="data.canManage" #action>
                    <FormDialog id="follow-service" :title="t('Follow a service')" :description="t('Any status page built on Atlassian Statuspage works.')" :action="`/api/app/projects/${project.id}/monitoring/third-party`" :submit="t('Follow')">
                        <template #trigger="{ open }"><AcmeBtn size="sm" icon="plus" @click="open">{{ t('Follow a service') }}</AcmeBtn></template>
                        <div class="grid gap-4">
                            <SelectField id="follow-provider" v-model="provider" name="provider" :label="t('Service')" :options="providers" />
                            <template v-if="provider === 'custom'">
                                <InputField id="follow-name" name="name" :label="t('Name (another status page)')" maxlength="80" />
                                <InputField id="follow-url" name="url" type="url" :label="t('Status page address')" maxlength="255" placeholder="https://status.example.com" />
                            </template>
                        </div>
                    </FormDialog>
                </template>
                <ul v-if="data.thirdParty.length > 0" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <li v-for="service in data.thirdParty" :key="service.id" class="group relative rounded-xl border border-line p-4">
                        <p class="flex items-center justify-between gap-2 font-medium text-ink">
                            <a :href="service.url" class="truncate hover:underline" rel="noopener noreferrer" target="_blank">{{ service.name }}</a>
                            <AcmeBadge :tone="acmeTone(service.tone)" dot>{{ service.label }}</AcmeBadge>
                        </p>
                        <p class="mt-1 text-xs text-muted">
                            <template v-if="service.affected.length > 0">{{ t('Affected: :components', { components: service.affected.join(', ') }) }} · </template>
                            <Rich v-if="service.checkedAt" :text="t('checked :time')"><template #time><RelativeTime :at="service.checkedAt" /></template></Rich>
                        </p>
                        <p v-if="service.summary" class="mt-1 text-xs text-muted">{{ service.summary }}</p>
                        <p v-if="service.error" class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ service.error }}</p>
                        <ApiForm v-if="data.canManage" :action="`/api/app/projects/${project.id}/monitoring/third-party/${service.id}`" method="DELETE" class="mt-2">
                            <SubmitButton variant="quiet" size="sm" :aria-label="t('Stop following :name', { name: service.name })">{{ t('Remove') }}</SubmitButton>
                        </ApiForm>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted">{{ t('Not following any services yet.') }}</p>
            </AcmeCard>
        </div>
    </div>
</template>
