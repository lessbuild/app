<script setup lang="ts">
import type { PublicStatusPage, PublicStatusUpdate } from '~/types/monitoring';

/**
 * A published status page, as anyone sees it: the overall state, current incidents and maintenance, each system with
 * its 30-day history, planned maintenance, subscribing (email, Slack or a signed webhook) and the last 30 days. Shown at
 * /status/{slug} and at the root of the page's custom domain. The account's white-label branding replaces ours.
 */
const props = defineProps<{ slug: string }>();
const { t, dateTime } = useT();
const route = useRoute();
const secrets = useSecrets();
const { data } = await useApi<PublicStatusPage>(() => `/status/${props.slug}`);
const page = computed(() => data.value.page);
const tone = (state: string) => ({ operational: 'success', major_outage: 'danger', maintenance: 'info' } as Record<string, 'success' | 'danger' | 'info'>)[state] ?? 'warning';
const banner = computed(() => ({ operational: 'border-success bg-success-soft', degraded: 'border-warning bg-warning-soft', major_outage: 'border-danger bg-danger-soft', maintenance: 'border-info bg-info-soft' })[data.value.overall]);
const groupLabel = (state: string) => (state === 'operational' ? t('Operational') : state === 'major_outage' ? t('Major outage') : t('Degraded performance'));
const updateTone = (update: PublicStatusUpdate) => (update.kind === 'maintenance' ? 'info' : update.severity === 'critical' ? 'danger' : 'warning');
const dayClass = (state: string) => ({ operational: 'bg-success', outage: 'bg-danger', degraded: 'bg-warning' } as Record<string, string>)[state] ?? 'bg-line';
const channels = computed(() => [{ value: 'slack', label: 'Slack' }, { value: 'webhook', label: t('Signed webhook') }]);
const month = new Date().toISOString().slice(0, 7);
// A component starts a group heading when its group differs from the one before it.
const startsGroup = (index: number) => {
    const group = data.value.components[index]?.group ?? null;
    return group !== null && group !== (data.value.components[index - 1]?.group ?? null);
};
const notice = computed(() => (typeof route.query.notice === 'string' ? route.query.notice : null));
useHead({
    title: () => page.value.name,
    meta: [{ name: 'description', content: () => page.value.description || t('Live service status and recent incident history.') }, { name: 'robots', content: 'index, follow' }],
    link: [{ rel: 'canonical', href: () => page.value.url }],
});
</script>

<template>
    <main id="main-content" tabindex="-1" class="mx-auto grid max-w-4xl gap-6 px-4 py-10 sm:px-8 sm:py-14" :style="data.branding?.color ? { '--ui-primary': data.branding.color } : undefined">
        <header class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="ui-eyebrow">{{ page.owner }}</p>
                <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">{{ page.name }}</h1>
                <p v-if="page.description" class="mt-3 max-w-2xl whitespace-pre-line text-sm leading-6 text-muted">{{ page.description }}</p>
            </div>
            <span v-if="data.branding" class="inline-flex shrink-0 items-center gap-3">
                <img v-if="data.branding.logo" :src="data.branding.logo" alt="" class="h-10 w-auto max-w-40 object-contain" referrerpolicy="no-referrer">
                <span v-else class="grid h-10 w-10 place-items-center rounded-card bg-primary text-sm font-extrabold text-on-primary" aria-hidden="true">{{ data.branding.name.slice(0, 1).toUpperCase() }}</span>
                <span class="sr-only">{{ data.branding.name }}</span>
            </span>
        </header>

        <Alert v-if="notice" tone="success" role="status">{{ notice }}</Alert>

        <section aria-live="polite" :class="['flex items-center gap-4 rounded-card border p-5 sm:p-6', banner]">
            <StatusDot :color="`var(--ui-${tone(data.overall)})`" size="lg" aria-hidden="true" />
            <div>
                <h2 class="text-lg font-extrabold text-ink">{{ data.overallLabel }}</h2>
                <p class="mt-0.5 text-sm text-muted">{{ t('Updated :time', { time: dateTime(data.checkedAt) }) }}</p>
            </div>
        </section>

        <section v-if="data.activeUpdates.length > 0" class="grid gap-3" aria-labelledby="active-updates">
            <h2 id="active-updates" class="sr-only">{{ t('Current incidents and maintenance') }}</h2>
            <Alert v-for="update in data.activeUpdates" :key="update.id" :tone="updateTone(update)">
                <div class="min-w-0">
                    <p class="text-xs font-bold uppercase tracking-wide">{{ update.kind === 'maintenance' ? t('Maintenance') : t('Incident') }} · {{ update.statusLabel }}</p>
                    <p class="mt-1 font-extrabold">{{ update.title }}</p>
                    <p class="mt-1 whitespace-pre-line text-sm">{{ update.message }}</p>
                    <p class="mt-2 text-xs">
                        {{ t('Started :time', { time: dateTime(update.startsAt) }) }}
                        <template v-if="update.updatedAt"> · <Rich :text="t('Last updated :time')"><template #time><RelativeTime :at="update.updatedAt" /></template></Rich></template>
                    </p>
                </div>
            </Alert>
        </section>

        <section class="ui-card overflow-hidden" aria-labelledby="systems">
            <div class="border-b border-line px-5 py-4 sm:px-6"><h2 id="systems" class="font-extrabold text-ink">{{ t('Systems') }}</h2></div>
            <div class="divide-y divide-line">
                <template v-for="(row, index) in data.components" :key="`${row.group}-${row.name}`">
                    <div v-if="startsGroup(index)" class="flex items-center justify-between gap-3 bg-surface-muted px-5 py-3 sm:px-6">
                        <h3 class="text-sm font-extrabold uppercase tracking-wide text-ink">{{ row.group }}</h3>
                        <Badge :tone="tone(data.groups[row.group!] ?? 'operational')">{{ groupLabel(data.groups[row.group!] ?? 'operational') }}</Badge>
                    </div>
                    <article class="px-5 py-5 sm:px-6">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="font-bold text-ink">{{ row.name }}</h3>
                                <p class="mt-0.5 text-xs text-muted">{{ row.type }}<template v-if="row.checkedAt"> · <Rich :text="t('Checked :time')"><template #time><RelativeTime :at="row.checkedAt" /></template></Rich></template></p>
                            </div>
                            <Badge :tone="tone(row.state)">{{ row.stateLabel }}</Badge>
                        </div>
                        <p v-for="incident in row.incidents" :key="incident.title" class="mt-3 text-sm text-danger">
                            {{ incident.title }} · <Rich :text="t('since :time')"><template #time><RelativeTime :at="incident.openedAt" /></template></Rich>
                        </p>
                        <div v-if="row.history" class="mt-4">
                            <div class="flex items-end justify-between gap-2 text-xs text-muted">
                                <span>{{ t('Last 30 days') }}</span>
                                <span class="font-bold text-ink">{{ row.history.uptime !== null ? t(':uptime% uptime', { uptime: row.history.uptime.toFixed(2) }) : t('Not enough data') }}</span>
                            </div>
                            <div class="mt-2 flex gap-0.5" role="img" :aria-label="t('30-day history for :component: :uptime', { component: row.name, uptime: row.history.uptime !== null ? `${row.history.uptime.toFixed(2)}%` : t('not enough data') })">
                                <span v-for="day in row.history.days" :key="day.date" :title="day.summary ? `${day.label} · ${day.summary}` : day.label" :class="['h-8 min-w-0 flex-1 rounded-sm', dayClass(day.state)]" />
                            </div>
                            <div class="mt-1 flex justify-between text-xs text-muted"><span>{{ t('30 days ago') }}</span><span>{{ t('Today') }}</span></div>
                        </div>
                    </article>
                </template>
                <p v-if="data.components.length === 0" class="px-5 py-8 text-sm text-muted sm:px-6">{{ t('No systems have been added to this page yet.') }}</p>
            </div>
        </section>

        <section v-if="data.upcomingMaintenance.length > 0" class="ui-card p-5 sm:p-6" aria-labelledby="planned">
            <h2 id="planned" class="font-extrabold text-ink">{{ t('Planned maintenance') }}</h2>
            <ul class="mt-3 grid gap-3">
                <li v-for="update in data.upcomingMaintenance" :key="update.id">
                    <p class="font-bold text-ink">{{ update.title }}</p>
                    <p class="text-xs text-muted">{{ dateTime(update.startsAt) }}<template v-if="update.endsAt"> – {{ dateTime(update.endsAt) }}</template></p>
                    <p class="mt-1 whitespace-pre-line text-sm text-muted">{{ update.message }}</p>
                </li>
            </ul>
        </section>

        <section class="ui-card p-5 sm:p-6" aria-labelledby="subscribe">
            <h2 id="subscribe" class="font-extrabold text-ink">{{ t('Get updates by email') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ t('We’ll email you when we post an incident or maintenance update. You can unsubscribe from any email.') }}</p>
            <ApiForm :action="`/api/app/status/${page.slug}/subscribe`" class="mt-4 flex flex-wrap items-end gap-3">
                <div class="min-w-0 flex-1 basis-64"><InputField id="subscribe-email" name="email" type="email" :label="t('Email address')" autocomplete="email" maxlength="254" required /></div>
                <SubmitButton>{{ t('Subscribe') }}</SubmitButton>
            </ApiForm>
            <Disclosure :title="t('Post updates to Slack or a webhook instead')" :open="!!secrets?.webhook_secret" class="mt-4">
                <Alert v-if="secrets?.webhook_secret" tone="info" class="mb-3">
                    {{ t('Your signing secret (shown once): :secret — requests carry X-BuildPusher-Signature: v1=HMAC-SHA256 of the timestamp, a dot and the body.', { secret: secrets.webhook_secret }) }}
                </Alert>
                <ApiForm :action="`/api/app/status/${page.slug}/subscribe/webhook`" class="grid items-end gap-3 sm:grid-cols-[10rem_1fr_auto]">
                    <SelectField id="webhook-channel" name="channel" :label="t('Where')" :options="channels" />
                    <InputField id="webhook-url" name="url" type="url" :label="t('Incoming webhook URL')" maxlength="2048" placeholder="https://hooks.slack.com/services/…" required />
                    <SubmitButton variant="secondary">{{ t('Subscribe') }}</SubmitButton>
                </ApiForm>
            </Disclosure>
        </section>

        <section v-if="data.pastUpdates.length > 0 || data.recentIncidents.length > 0" class="ui-card overflow-hidden" aria-labelledby="past">
            <div class="border-b border-line px-5 py-4 sm:px-6"><h2 id="past" class="font-extrabold text-ink">{{ t('Past 30 days') }}</h2></div>
            <ul class="divide-y divide-line">
                <li v-for="update in data.pastUpdates" :key="`update-${update.id}`" class="px-5 py-4 sm:px-6">
                    <p class="font-bold text-ink">{{ update.title }}</p>
                    <p class="text-xs text-muted">{{ update.statusLabel }} · {{ dateTime(update.startsAt) }}</p>
                    <p class="mt-1 whitespace-pre-line text-sm text-muted">{{ update.message }}</p>
                    <p v-if="update.rootCause" class="mt-2 text-sm"><span class="font-bold text-ink">{{ t('What happened') }}:</span> <span class="whitespace-pre-line text-muted">{{ update.rootCause }}</span></p>
                    <p v-if="update.remediation" class="mt-2 text-sm"><span class="font-bold text-ink">{{ t('What we did') }}:</span> <span class="whitespace-pre-line text-muted">{{ update.remediation }}</span></p>
                    <p v-if="update.followUp" class="mt-2 text-sm"><span class="font-bold text-ink">{{ t('What’s next') }}:</span> <span class="whitespace-pre-line text-muted">{{ update.followUp }}</span></p>
                </li>
                <li v-for="(incident, index) in data.recentIncidents" :key="`incident-${index}`" class="px-5 py-4 sm:px-6">
                    <p class="font-bold text-ink">{{ incident.title }}</p>
                    <p class="text-xs text-muted">{{ t('Resolved') }} · {{ dateTime(incident.openedAt) }}<template v-if="incident.resolvedAt"> – {{ dateTime(incident.resolvedAt) }}</template></p>
                </li>
            </ul>
        </section>

        <footer class="flex flex-wrap justify-between gap-3 text-xs text-muted">
            <span>{{ data.branding ? '' : t('Powered by :app', { app: 'BuildPusher' }) }}</span>
            <span class="flex gap-3">
                <NuxtLink :to="`/status/${page.slug}/uptime/${month}`" class="hover:underline">{{ t('Monthly uptime') }}</NuxtLink>
                <a :href="page.reportUrl" class="hover:underline">{{ t('JSON') }}</a>
            </span>
        </footer>
    </main>
</template>
