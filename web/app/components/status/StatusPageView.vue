<script setup lang="ts">
import type { PublicStatusPage, PublicStatusUpdate } from '~/types/monitoring';

/**
 * A published status page, as anyone sees it: the overall state, current incidents and maintenance, each system with
 * its 30-day history, planned maintenance, subscribing (email, Slack or a signed webhook) and the last 30 days. Shown at
 * /status/{slug} and at the root of the page's custom domain, in the Acme theme's status page. The account's white-label
 * branding replaces ours.
 */
const props = defineProps<{ slug: string }>();
const { t, dateTime } = useT();
const route = useRoute();
const secrets = useSecrets();
const { data } = await useApi<PublicStatusPage>(() => `/status/${props.slug}`);
const page = computed(() => data.value.page);
const banner = computed(() => ({ operational: 'bg-emerald-600', degraded: 'bg-amber-500', major_outage: 'bg-rose-600', maintenance: 'bg-blue-600' })[data.value.overall]);
const stateText = (state: string) => ({ operational: 'text-emerald-600 dark:text-emerald-400', major_outage: 'text-rose-600 dark:text-rose-400', maintenance: 'text-blue-600 dark:text-blue-400' } as Record<string, string>)[state] ?? 'text-amber-600 dark:text-amber-400';
const hover = ref<string | null>(null);
const groupLabel = (state: string) => (state === 'operational' ? t('Operational') : state === 'major_outage' ? t('Major outage') : t('Degraded performance'));
const updateTone = (update: PublicStatusUpdate) => (update.kind === 'maintenance' ? 'info' : update.severity === 'critical' ? 'danger' : 'warning');
const dayClass = (state: string) => ({ operational: 'bg-emerald-500', outage: 'bg-rose-500', degraded: 'bg-amber-400' } as Record<string, string>)[state] ?? 'bg-line';
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
    <div class="min-h-dvh bg-page" :style="data.branding?.color ? { '--ui-primary': data.branding.color } : undefined">
        <header class="border-b border-line">
            <div class="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-4 px-5 py-5">
                <p class="flex min-w-0 items-center gap-2 font-semibold text-ink">
                    <img v-if="data.branding?.logo" :src="data.branding.logo" alt="" class="h-7 w-auto max-w-32 object-contain" referrerpolicy="no-referrer">
                    <span v-else class="grid size-7 shrink-0 place-items-center rounded-md bg-primary text-sm text-on-primary" aria-hidden="true">{{ (data.branding?.name ?? page.owner).slice(0, 1).toUpperCase() }}</span>
                    <span class="truncate">{{ data.branding?.name ?? page.owner }}</span> <span class="font-normal text-muted">{{ t('Status') }}</span>
                </p>
                <ApiForm :action="`/api/app/status/${page.slug}/subscribe`" class="!flex flex-wrap items-start gap-2">
                    <label class="sr-only" for="status-email">{{ t('Email address') }}</label>
                    <input id="status-email" name="email" type="email" autocomplete="email" maxlength="254" required placeholder="you@example.com" class="control hidden w-52 sm:block">
                    <AcmeBtn type="submit" variant="primary" size="sm" icon="bell">{{ t('Subscribe') }}</AcmeBtn>
                </ApiForm>
            </div>
        </header>
        <main id="main-content" tabindex="-1" class="mx-auto max-w-3xl space-y-10 px-5 py-10">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ page.name }}</h1>
                <p v-if="page.description" class="mt-2 whitespace-pre-line text-muted">{{ page.description }}</p>
            </div>

            <AcmeAlert v-if="notice" tone="success" role="status">{{ notice }}</AcmeAlert>

            <div :class="['flex flex-wrap items-center gap-3 rounded-xl px-5 py-4 text-white', banner]" role="status" aria-live="polite">
                <AcmeIcon :name="data.overall === 'operational' ? 'checkCircle' : 'alert'" :size="22" />
                <p class="font-semibold">{{ data.overallLabel }}</p>
                <span class="ml-auto text-sm opacity-80">{{ t('Updated :time', { time: dateTime(data.checkedAt) }) }}</span>
            </div>

            <section v-if="data.activeUpdates.length > 0" class="space-y-3" aria-labelledby="active-updates">
                <h2 id="active-updates" class="sr-only">{{ t('Current incidents and maintenance') }}</h2>
                <AcmeAlert v-for="update in data.activeUpdates" :key="update.id" :tone="updateTone(update)" :title="update.title">
                    <p class="text-xs font-medium uppercase tracking-wide">{{ update.kind === 'maintenance' ? t('Maintenance') : t('Incident') }} · {{ update.statusLabel }}</p>
                    <p class="mt-1 whitespace-pre-line">{{ update.message }}</p>
                    <p class="mt-2 text-xs">
                        {{ t('Started :time', { time: dateTime(update.startsAt) }) }}
                        <template v-if="update.updatedAt"> · <Rich :text="t('Last updated :time')"><template #time><RelativeTime :at="update.updatedAt" /></template></Rich></template>
                    </p>
                </AcmeAlert>
            </section>

            <section class="rounded-2xl border border-line bg-surface shadow-card" :aria-label="t('Systems')">
                <ul class="divide-y divide-line">
                    <template v-for="(row, index) in data.components" :key="`${row.group}-${row.name}`">
                        <li v-if="startsGroup(index)" class="flex items-center justify-between gap-3 bg-black/[.015] px-5 py-3 dark:bg-white/[.02]">
                            <h2 class="section-label">{{ row.group }}</h2>
                            <span :class="['text-sm', stateText(data.groups[row.group!] ?? 'operational')]">{{ groupLabel(data.groups[row.group!] ?? 'operational') }}</span>
                        </li>
                        <li class="space-y-3 p-5">
                            <div class="flex items-center justify-between gap-3 text-sm">
                                <span class="min-w-0"><span class="font-medium text-ink">{{ row.name }}</span><span class="block text-xs text-muted">{{ row.type }}<template v-if="row.checkedAt"> · <Rich :text="t('Checked :time')"><template #time><RelativeTime :at="row.checkedAt" /></template></Rich></template></span></span>
                                <span :class="['flex shrink-0 items-center gap-1.5', stateText(row.state)]"><AcmeIcon :name="row.state === 'operational' ? 'checkCircle' : 'alert'" :size="15" />{{ row.stateLabel }}</span>
                            </div>
                            <p v-for="incident in row.incidents" :key="incident.title" class="text-sm text-rose-600 dark:text-rose-400">
                                {{ incident.title }} · <Rich :text="t('since :time')"><template #time><RelativeTime :at="incident.openedAt" /></template></Rich>
                            </p>
                            <div v-if="row.history">
                                <div class="relative flex h-8 gap-px" role="img" :aria-label="t('30-day history for :component: :uptime', { component: row.name, uptime: row.history.uptime !== null ? `${row.history.uptime.toFixed(2)}%` : t('not enough data') })" @mouseleave="hover = null">
                                    <span v-for="(day, dayIndex) in row.history.days" :key="day.date" :class="['flex-1 rounded-[2px] transition-opacity', dayClass(day.state), hover && hover !== `${index}-${dayIndex}` && hover.startsWith(`${index}-`) && 'opacity-50']" @mouseenter="hover = `${index}-${dayIndex}`" />
                                    <template v-for="(day, dayIndex) in row.history.days" :key="`tip-${day.date}`">
                                        <span v-if="hover === `${index}-${dayIndex}`" class="pointer-events-none absolute -top-9 z-10 -translate-x-1/2 whitespace-nowrap rounded-md bg-zinc-900 px-2 py-1 text-xs text-white shadow" :style="{ left: `${((dayIndex + 0.5) / row.history.days.length) * 100}%` }">{{ day.summary ? `${day.label} · ${day.summary}` : day.label }}</span>
                                    </template>
                                </div>
                                <div class="mt-1.5 flex justify-between text-xs text-muted"><span>{{ t('30 days ago') }}</span><span>{{ row.history.uptime !== null ? t(':uptime% uptime', { uptime: row.history.uptime.toFixed(2) }) : t('Not enough data') }}</span><span>{{ t('Today') }}</span></div>
                            </div>
                        </li>
                    </template>
                    <li v-if="data.components.length === 0" class="p-5 text-sm text-muted">{{ t('No systems have been added to this page yet.') }}</li>
                </ul>
            </section>

            <section v-if="data.upcomingMaintenance.length > 0" aria-labelledby="planned">
                <h2 id="planned" class="mb-4 text-lg font-semibold text-ink">{{ t('Planned maintenance') }}</h2>
                <div class="space-y-6">
                    <article v-for="update in data.upcomingMaintenance" :key="update.id" class="rounded-2xl border border-line bg-surface p-5 shadow-card">
                        <div class="flex flex-wrap items-center gap-2"><h3 class="font-semibold text-ink">{{ update.title }}</h3><AcmeBadge tone="blue">{{ t('Maintenance') }}</AcmeBadge></div>
                        <p class="mt-1 text-sm text-muted">{{ dateTime(update.startsAt) }}<template v-if="update.endsAt"> – {{ dateTime(update.endsAt) }}</template></p>
                        <p class="mt-3 whitespace-pre-line text-sm text-ink">{{ update.message }}</p>
                    </article>
                </div>
            </section>

            <section v-if="data.pastUpdates.length > 0 || data.recentIncidents.length > 0" aria-labelledby="past">
                <h2 id="past" class="mb-4 text-lg font-semibold text-ink">{{ t('Past 30 days') }}</h2>
                <div class="space-y-6">
                    <article v-for="update in data.pastUpdates" :key="`update-${update.id}`" class="rounded-2xl border border-line bg-surface p-5 shadow-card">
                        <div class="flex flex-wrap items-center gap-2"><h3 class="font-semibold text-ink">{{ update.title }}</h3><AcmeBadge :tone="update.kind === 'maintenance' ? 'blue' : 'green'">{{ update.statusLabel }}</AcmeBadge></div>
                        <p class="mt-1 text-sm text-muted">{{ dateTime(update.startsAt) }}</p>
                        <p class="mt-3 whitespace-pre-line text-sm text-ink">{{ update.message }}</p>
                        <p v-if="update.rootCause" class="mt-2 text-sm"><span class="font-medium text-ink">{{ t('What happened') }}:</span> <span class="whitespace-pre-line text-muted">{{ update.rootCause }}</span></p>
                        <p v-if="update.remediation" class="mt-2 text-sm"><span class="font-medium text-ink">{{ t('What we did') }}:</span> <span class="whitespace-pre-line text-muted">{{ update.remediation }}</span></p>
                        <p v-if="update.followUp" class="mt-2 text-sm"><span class="font-medium text-ink">{{ t('What’s next') }}:</span> <span class="whitespace-pre-line text-muted">{{ update.followUp }}</span></p>
                    </article>
                    <article v-for="(incident, incidentIndex) in data.recentIncidents" :key="`incident-${incidentIndex}`" class="rounded-2xl border border-line bg-surface p-5 shadow-card">
                        <div class="flex flex-wrap items-center gap-2"><h3 class="font-semibold text-ink">{{ incident.title }}</h3><AcmeBadge tone="green">{{ t('Resolved') }}</AcmeBadge></div>
                        <p class="mt-1 text-sm text-muted">{{ dateTime(incident.openedAt) }}<template v-if="incident.resolvedAt"> – {{ dateTime(incident.resolvedAt) }}</template></p>
                    </article>
                </div>
            </section>

            <Disclosure :title="t('Post updates to Slack or a webhook instead')" :open="!!secrets?.webhook_secret">
                <Alert v-if="secrets?.webhook_secret" tone="info" class="mb-3">
                    {{ t('Your signing secret (shown once): :secret — requests carry X-BuildPusher-Signature: v1=HMAC-SHA256 of the timestamp, a dot and the body.', { secret: secrets.webhook_secret }) }}
                </Alert>
                <ApiForm :action="`/api/app/status/${page.slug}/subscribe/webhook`" class="grid items-end gap-3 sm:grid-cols-[10rem_1fr_auto]">
                    <SelectField id="webhook-channel" name="channel" :label="t('Where')" :options="channels" />
                    <InputField id="webhook-url" name="url" type="url" :label="t('Incoming webhook URL')" maxlength="2048" placeholder="https://hooks.slack.com/services/…" required />
                    <SubmitButton variant="secondary">{{ t('Subscribe') }}</SubmitButton>
                </ApiForm>
            </Disclosure>

            <p class="text-center text-sm text-muted">
                <template v-if="!data.branding">{{ t('Powered by :app', { app: 'BuildPusher' }) }} · </template>
                <NuxtLink :to="`/status/${page.slug}/uptime/${month}`" class="underline">{{ t('Monthly uptime') }}</NuxtLink> ·
                <a :href="page.reportUrl" class="underline">{{ t('JSON') }}</a>
            </p>
        </main>
    </div>
</template>
