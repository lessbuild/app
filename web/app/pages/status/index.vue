<script setup lang="ts">
/**
 * BuildPusher's own status (the Acme theme's status page): whether everything is working, each part with its last 90
 * days and uptime, the recent incidents with their timeline, and email updates. Or the status page set for the
 * platform, when there is one.
 */
definePageMeta({ layout: 'public' });
type Component = { key: string; name: string; description: string; status: string; operational: boolean; days: Array<'up' | 'degraded' | 'down' | 'none'>; uptime: number | null };
type Incident = { id: number; name: string; startedAt: string; resolvedAt: string | null; minutes: number | null };
type PlatformStatus = { redirect?: string; operational: boolean; checkedAt: string; components: Component[]; historyDays: number; incidents: Incident[]; reportUrl: string };
const { t, tc, dateTime } = useT();
const route = useRoute();
const { data } = await useApi<PlatformStatus>('/platform-status');
if (data.value.redirect) {
    await navigateTo(data.value.redirect, { replace: true });
}
useHead({ title: () => t(':app status', { app: 'BuildPusher' }), meta: [{ name: 'description', content: () => t('Live status of the platform and its services, with 90 days of uptime and recent incidents.') }] });
const notice = computed(() => (typeof route.query.notice === 'string' ? route.query.notice : null));
const email = ref('');
const sent = ref<string | null>(null);
const busy = ref(false);
const failed = ref(false);
const open = computed(() => data.value.incidents.filter((incident) => incident.resolvedAt === null));

/** Ask for status emails; the confirmation link arrives by email. */
async function subscribe() {
    busy.value = true;
    failed.value = false;
    const result = await send<{ message: string }>('POST', '/platform-status/subscribe', { email: email.value }).catch(() => null);
    busy.value = false;
    if (result === null) {
        failed.value = true;
        return;
    }
    sent.value = result.message;
    email.value = '';
}

/**
 * How long an incident lasted, in words.
 *
 * @param minutes Its length in minutes.
 */
const lasted = (minutes: number) => (minutes >= 120 ? tc(':count hour|:count hours', Math.round(minutes / 60)) : tc(':count minute|:count minutes', minutes));

/**
 * An incident's timeline, newest first: resolved (when it is), then when the checks noticed it.
 *
 * @param incident The incident.
 */
const timeline = (incident: Incident) => [
    ...(incident.resolvedAt ? [{ title: t('Resolved'), time: dateTime(incident.resolvedAt), body: t(':name is working normally again, after :time.', { name: incident.name, time: lasted(incident.minutes ?? 1) }), tone: 'bg-emerald-500 text-white' }] : []),
    { title: t('Investigating'), time: dateTime(incident.startedAt), body: t('Our checks found :name not working as it should. We’re looking into it.', { name: incident.name }), tone: incident.resolvedAt ? 'bg-amber-400 text-white' : 'bg-rose-500 text-white' },
];
</script>

<template>
    <div v-if="!data.redirect" class="mx-auto max-w-3xl space-y-10 px-5 py-10">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ t(':app status', { app: 'BuildPusher' }) }}</h1>
            <form class="flex w-full gap-2 sm:w-auto" novalidate @submit.prevent="subscribe">
                <label class="sr-only" for="status-email">{{ t('Email') }}</label>
                <input id="status-email" v-model="email" type="email" required autocomplete="email" placeholder="you@company.com" class="ui-input min-w-0 flex-1 sm:w-56">
                <AcmeBtn type="submit" variant="primary" size="sm" icon="bell" :loading="busy">{{ t('Subscribe') }}</AcmeBtn>
            </form>
        </div>
        <AcmeAlert v-if="notice" tone="success" role="status">{{ notice }}</AcmeAlert>
        <AcmeAlert v-if="sent" tone="success" role="status">{{ sent }}</AcmeAlert>
        <AcmeAlert v-else-if="failed" tone="danger" role="alert">{{ t('Enter a valid email address and try again.') }}</AcmeAlert>

        <div :class="['flex flex-wrap items-center gap-3 rounded-xl px-5 py-4 text-white', data.operational ? 'bg-emerald-600' : 'bg-amber-500']" role="status" aria-live="polite">
            <AcmeIcon :name="data.operational ? 'checkCircle' : 'alert'" :size="22" />
            <p class="font-semibold">{{ data.operational ? t('All systems operational') : t('Some systems are degraded') }}</p>
            <span class="ml-auto text-sm opacity-80">{{ t('Checked :time', { time: dateTime(data.checkedAt) }) }}</span>
        </div>

        <section class="rounded-2xl border border-line bg-surface shadow-card" :aria-label="t('Platform status')">
            <ul class="divide-y divide-line">
                <li v-for="part in data.components" :key="part.key" class="space-y-3 p-5">
                    <div class="flex items-start justify-between gap-3 text-sm">
                        <span class="min-w-0"><span class="block font-medium text-ink">{{ part.name }}</span><span class="block text-xs text-muted">{{ part.description }}</span></span>
                        <span :class="['flex shrink-0 items-center gap-1.5', part.operational ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400']"><AcmeIcon :name="part.operational ? 'checkCircle' : 'alert'" :size="15" />{{ part.status }}</span>
                    </div>
                    <template v-if="part.days.length > 0">
                        <AcmeUptimeBar :days="part.days" :label="part.name" compact />
                        <div class="flex justify-between text-xs text-muted">
                            <span>{{ t(':count days ago', { count: data.historyDays }) }}</span>
                            <span>{{ part.uptime === null ? t('No history yet') : t(':pct% uptime', { pct: part.uptime.toFixed(2) }) }}</span>
                            <span>{{ t('Today') }}</span>
                        </div>
                    </template>
                </li>
            </ul>
        </section>

        <section>
            <h2 class="mb-4 text-lg font-semibold text-ink">{{ open.length > 0 ? t('Incidents') : t('Recent incidents') }}</h2>
            <div v-if="data.incidents.length > 0" class="space-y-6">
                <article v-for="incident in data.incidents" :key="incident.id" class="rounded-2xl border border-line bg-surface p-5 shadow-card">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="font-semibold text-ink">{{ t(':name degraded', { name: incident.name }) }}</h3>
                        <AcmeBadge :tone="incident.resolvedAt ? 'green' : 'amber'" dot>{{ incident.resolvedAt ? t('Resolved') : t('Investigating') }}</AcmeBadge>
                    </div>
                    <p class="mt-1 text-sm text-muted">{{ incident.resolvedAt ? t(':start to :end', { start: dateTime(incident.startedAt), end: dateTime(incident.resolvedAt) }) : t('Since :time', { time: dateTime(incident.startedAt) }) }}</p>
                    <AcmeTimeline class="mt-5" :items="timeline(incident)" />
                </article>
            </div>
            <p v-else class="rounded-2xl border border-line bg-surface p-5 text-sm text-muted shadow-card">{{ t('No incidents in the last 30 days.') }}</p>
        </section>

        <p class="text-center text-sm text-muted">
            {{ t('Checked every five minutes.') }} <a :href="data.reportUrl" class="underline">{{ t('JSON report') }}</a> · <a href="/status/badge.svg" class="underline">{{ t('Status badge') }}</a>
        </p>
    </div>
</template>
