<script setup lang="ts">
import type { ServerPage } from '~/types/infrastructure';

/**
 * A server's overview (the Acme theme's server overview): its latest CPU, memory, disk and load, CPU over the last day
 * by the hour, its details and cost, the websites on it, its alerts and diagnostics.
 */
const props = defineProps<{ page: ServerPage; base: string }>();
const { t, number } = useT();
const money = useMoney();
const server = computed(() => props.page.server);
const project = computed(() => props.page.overview.project.id);
const hours = computed(() => {
    const byHour = new Map<string, number[]>();
    for (const reading of props.page.cpu) {
        const at = new Date(reading.at);
        const label = `${String(at.getHours()).padStart(2, '0')}h`;
        byHour.set(label, [...(byHour.get(label) ?? []), reading.value]);
    }
    return [...byHour].map(([label, values]) => ({ label, value: Math.round(values.reduce((total, value) => total + value, 0) / values.length) }));
});
const meters = computed(() => (props.page.latest === null ? [] : [
    { label: t('CPU'), value: props.page.latest.cpu },
    { label: t('Memory'), value: props.page.latest.memory },
    { label: t('Disk'), value: props.page.latest.disk },
]));
const details = computed(() => [
    { label: t('Address'), value: server.value.ip ?? '—', mono: true },
    { label: t('Private IP'), value: server.value.privateIp ?? t('None'), mono: true },
    { label: t('SSH'), value: `root@${server.value.ip ?? '—'}:${server.value.sshPort}`, mono: true },
    { label: t('Size'), value: server.value.size ?? '—', mono: false },
    { label: t('Image'), value: server.value.image ?? '—', mono: false },
    { label: t('Per month'), value: props.page.monthlyCost !== null && props.page.currency ? money.amount(props.page.monthlyCost, props.page.currency) : '—', mono: false },
    { label: t('Used by'), value: props.page.usedBy.join(', ') || t('No project'), mono: false },
]);

/**
 * The colour of a usage bar: red when nearly full, amber when busy, green otherwise.
 *
 * @param value The percentage used.
 */
const bar = (value: number) => (value > 80 ? 'bg-rose-500' : value > 60 ? 'bg-amber-500' : 'bg-emerald-500');
</script>

<template>
    <div class="space-y-6">
        <template v-if="server.status === 'active'">
            <p v-if="page.latest === null" class="rounded-2xl border border-line bg-surface p-5 text-sm text-muted shadow-card">{{ t('Metrics are collected every five minutes. The first reading appears shortly.') }}</p>
            <div v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div v-for="meter in meters" :key="meter.label" class="rounded-2xl border border-line bg-surface p-4 shadow-card">
                    <p class="text-sm text-muted">{{ meter.label }}</p>
                    <p class="mt-2 text-2xl font-semibold tabular-nums text-ink">{{ meter.value }}%</p>
                    <AcmeProgress :value="meter.value" :label="meter.label" size="sm" :color="bar(meter.value)" class="mt-3" />
                </div>
                <div class="rounded-2xl border border-line bg-surface p-4 shadow-card">
                    <p class="text-sm text-muted">{{ t('Load (1 min)') }}</p>
                    <p class="mt-2 text-2xl font-semibold tabular-nums text-ink">{{ number(page.latest.load) }}</p>
                    <p class="mt-3 text-xs text-muted">{{ t('up :days days · :processes processes', { days: page.latest.uptimeDays, processes: page.latest.processes }) }}</p>
                </div>
            </div>
        </template>

        <div class="grid gap-6 xl:grid-cols-3">
            <AcmeCard v-if="server.status === 'active'" :title="t('CPU over the last 24 hours')" class="xl:col-span-2">
                <template v-if="page.latest" #action><span class="text-xs text-muted"><Rich :text="t('Last reading :time')"><template #time><RelativeTime :at="page.latest.recordedAt" /></template></Rich></span></template>
                <AcmeBarChart v-if="hours.length > 0" :label="t('CPU use per hour, last 24 hours')" :data="hours" :format="(value: number) => `${value}%`" :height="180" highlight="max" />
                <p v-else class="text-sm text-muted">{{ t('No readings yet.') }}</p>
            </AcmeCard>
            <AcmeCard v-else :title="t('Resources')" class="xl:col-span-2">
                <p class="text-sm text-muted">{{ t('Resources, alerts and diagnostics appear once the server is active.') }}</p>
            </AcmeCard>
            <AcmeCard :title="t('Details')">
                <dl class="space-y-2.5 text-sm">
                    <div v-for="item in details" :key="item.label" class="flex justify-between gap-3">
                        <dt class="shrink-0 text-muted">{{ item.label }}</dt>
                        <dd :class="['truncate text-right text-ink', item.mono && 'font-mono text-xs']" :title="item.value">{{ item.value }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="shrink-0 text-muted">{{ t('Host key') }}</dt>
                        <dd class="truncate text-right font-mono text-xs text-ink" :title="server.hostFingerprint ?? undefined">{{ server.hostFingerprint ?? '—' }}</dd>
                    </div>
                </dl>
            </AcmeCard>
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <AcmeCard :title="t('Websites')" :link="{ label: t('All websites'), to: `/projects/${project}/infrastructure/websites` }" :padded="false">
                <ul v-if="page.websites.length > 0" class="divide-y divide-line text-sm">
                    <li v-for="website in page.websites" :key="website.id">
                        <NuxtLink :to="`/projects/${project}/infrastructure/websites/${website.id}`" class="flex items-center gap-3 px-5 py-3 hover:bg-black/[.02] sm:px-6 dark:hover:bg-white/[.03]">
                            <AcmeIcon name="globe" :size="15" class="text-muted" />
                            <span class="min-w-0 flex-1 truncate font-medium text-ink">{{ website.url }}</span>
                            <span class="text-xs text-muted">PHP {{ website.php }}</span>
                            <WebsiteStatusBadge :status="website.status" />
                        </NuxtLink>
                    </li>
                </ul>
                <p v-else class="px-5 pb-5 text-sm text-muted sm:px-6">{{ t('No websites on this server.') }}</p>
            </AcmeCard>
            <ServerAlerts v-if="server.status === 'active'" :page="page" :base="base" />
        </div>

        <ServerDiagnostics v-if="server.status === 'active'" :page="page" :base="base" />
    </div>
</template>
