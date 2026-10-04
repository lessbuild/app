<script setup lang="ts">
import type { DashboardWidgetData } from '~/types/monitoring';
import type { Tone } from '~/types/ui';

/** One dashboard widget: telemetry totals and trend, the event mix, open incidents, monitor health, SLOs or projects. */
defineProps<{ type: string; label: string; data: DashboardWidgetData }>();
const { t, tc, number, locale } = useT();
const decimal = (value: number) => new Intl.NumberFormat(locale.value, { maximumFractionDigits: 1 }).format(value);
const change = (value: number | null) => (value === null ? '' : `${value > 0 ? '+' : ''}${decimal(value)}%`);
const types = computed<Record<string, string>>(() => ({
    request: t('Requests'), query: t('Queries'), job: t('Jobs'), exception: t('Exceptions'), log: t('Logs'), metric: t('Metrics'), span: t('Spans'),
}));
/** All events in the mix, so each bar shows its type's share. */
const total = (breakdown: Record<string, number>) => Math.max(1, Object.values(breakdown).reduce((sum, count) => sum + count, 0));
const incidentTone = (status: string): Tone => (status === 'acknowledged' ? 'warning' : 'danger');
</script>

<template>
    <section class="ui-card min-w-0 p-5 sm:p-6" :class="{ 'xl:col-span-2': type === 'telemetry' }" :aria-label="label">
        <h2 class="text-lg font-extrabold text-ink">{{ label }}</h2>

        <template v-if="type === 'telemetry' && data.summary">
            <dl class="mt-4 grid gap-3 sm:grid-cols-4">
                <div class="rounded-control bg-surface-muted p-4">
                    <dt class="text-xs text-muted">{{ t('Events') }}</dt>
                    <dd class="mt-1 text-2xl font-extrabold text-ink tabular-nums">{{ number(data.summary.eventCount) }}</dd>
                    <dd class="text-xs text-muted">{{ change(data.summary.changes.events) }}</dd>
                </div>
                <div class="rounded-control bg-surface-muted p-4">
                    <dt class="text-xs text-muted">{{ t('Requests') }}</dt>
                    <dd class="mt-1 text-2xl font-extrabold text-ink tabular-nums">{{ number(data.summary.requestCount) }}</dd>
                </div>
                <div class="rounded-control bg-surface-muted p-4">
                    <dt class="text-xs text-muted">{{ t('Average duration') }}</dt>
                    <dd class="mt-1 text-2xl font-extrabold text-ink tabular-nums">{{ data.summary.averageDuration === null ? '—' : `${decimal(data.summary.averageDuration)} ms` }}</dd>
                    <dd class="text-xs text-muted">{{ change(data.summary.changes.duration) }}</dd>
                </div>
                <div class="rounded-control bg-surface-muted p-4">
                    <dt class="text-xs text-muted">{{ t('Failed requests') }}</dt>
                    <dd class="mt-1 text-2xl font-extrabold text-ink tabular-nums">{{ data.summary.requestErrorRate === null ? '—' : `${decimal(data.summary.requestErrorRate)}%` }}</dd>
                    <dd class="text-xs text-muted">{{ change(data.summary.changes.errorRate) }}</dd>
                </div>
            </dl>
            <div class="mt-4">
                <BarChart :label="t('Requests per period')" :points="data.summary.trend.map((point) => ({ label: point.label, value: point.requestCount }))" :unit="t('requests')" :max="1" />
            </div>
            <p class="mt-1 text-xs text-muted">{{ t('Changes compare with the period before. Failed means a 5xx status or an error severity.') }}</p>
        </template>

        <ul v-else-if="type === 'event_mix' && data.summary" class="mt-4 grid gap-3" :aria-label="t('Events by type')">
            <li v-for="(count, kind) in data.summary.eventBreakdown" :key="kind" class="grid gap-1">
                <div class="flex justify-between text-sm"><span class="font-bold text-ink">{{ types[kind] ?? kind }}</span><span class="tabular-nums text-muted">{{ number(count) }}</span></div>
                <ProgressBar :value="count > 0 ? Math.max(1, (count / total(data.summary.eventBreakdown)) * 100) : 0" :label="types[kind] ?? String(kind)" />
            </li>
        </ul>

        <template v-else-if="type === 'incidents'">
            <p v-if="!data.incidents?.length" class="mt-3 text-sm text-muted">{{ t('No open incidents.') }}</p>
            <ul v-else class="mt-3 divide-y divide-line">
                <li v-for="incident in data.incidents" :key="incident.id" class="flex flex-wrap items-center justify-between gap-2 py-3">
                    <div class="min-w-0">
                        <NuxtLink :to="`/projects/${incident.projectId}/monitoring/incidents/${incident.id}`" class="font-bold text-ink hover:underline">{{ incident.title }}</NuxtLink>
                        <p class="text-xs text-muted">{{ incident.project }} · <Rich :text="t('since :time')"><template #time><RelativeTime :at="incident.openedAt" /></template></Rich></p>
                    </div>
                    <Badge :tone="incidentTone(incident.status)">{{ incident.statusLabel }}</Badge>
                </li>
            </ul>
        </template>

        <template v-else-if="type === 'monitors'">
            <p v-if="!data.monitors?.length" class="mt-3 text-sm text-muted">{{ t('No monitors yet.') }}</p>
            <ul v-else class="mt-3 divide-y divide-line">
                <li v-for="monitor in data.monitors" :key="monitor.id" class="flex flex-wrap items-center justify-between gap-2 py-3">
                    <div class="min-w-0">
                        <NuxtLink :to="`/projects/${monitor.projectId}/monitoring/monitors/${monitor.id}`" class="font-bold text-ink hover:underline">{{ monitor.name }}</NuxtLink>
                        <p class="text-xs text-muted">{{ monitor.typeLabel }} · {{ monitor.project }} / {{ monitor.environment }}</p>
                    </div>
                    <HealthBadge :health="monitor.health" :label="monitor.healthLabel" />
                </li>
            </ul>
        </template>

        <template v-else-if="type === 'objectives'">
            <p v-if="!data.objectives?.length" class="mt-3 text-sm text-muted">{{ t('No SLOs yet.') }}</p>
            <ul v-else class="mt-3 divide-y divide-line">
                <li v-for="objective in data.objectives" :key="objective.id" class="flex flex-wrap items-center justify-between gap-2 py-3">
                    <div class="min-w-0">
                        <NuxtLink :to="`/projects/${objective.projectId}/monitoring/objectives/${objective.id}`" class="font-bold text-ink hover:underline">{{ objective.name }}</NuxtLink>
                        <p class="text-xs text-muted">{{ objective.project }} · {{ t(':target% target', { target: objective.target }) }} · {{ objective.compliance === null ? '—' : `${objective.compliance}%` }}</p>
                    </div>
                    <ObjectiveBadge :status="objective.status" :enabled="objective.enabled" />
                </li>
            </ul>
        </template>

        <ul v-else-if="type === 'projects'" class="mt-3 divide-y divide-line">
            <li v-for="item in data.projects" :key="item.id" class="flex flex-wrap items-center justify-between gap-2 py-3">
                <div class="min-w-0">
                    <NuxtLink :to="`/projects/${item.id}`" class="font-bold text-ink hover:underline">{{ item.name }}</NuxtLink>
                    <p class="text-xs text-muted">{{ tc(':count environment|:count environments', item.environments) }}</p>
                </div>
                <span class="text-xs text-muted">
                    <Rich v-if="item.lastReceivedAt" :text="t('Telemetry :time')"><template #time><RelativeTime :at="item.lastReceivedAt" /></template></Rich>
                    <template v-else>{{ t('No telemetry yet') }}</template>
                </span>
            </li>
        </ul>
    </section>
</template>
