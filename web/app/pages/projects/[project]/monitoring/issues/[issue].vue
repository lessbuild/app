<script setup lang="ts">
import type { EventRow, IssueRow } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';
import type { Option } from '~/types/ui';

/**
 * One issue (the Acme theme's issue page): how often it happens and to how many people, its last 12 hours, its stack trace
 * (your own code first), where it happens and its latest occurrence, its recent occurrences and activity, and resolving,
 * snoozing, ignoring or assigning it.
 */
definePageMeta({ layout: 'app', service: 'monitoring' });
type IssuePage = {
    overview: ProjectOverview;
    issue: IssueRow & {
        version: number;
        details: string | null;
        firstSeenAt: string | null;
        snoozedUntil: string | null;
        assigneeId: string | null;
        environment: string | null;
        ticketUrl: string | null;
        ticketKey: string | null;
        open: boolean;
        users: number;
        trend: number[];
        where: Array<{ label: string; value: number }>;
        firstRelease: string | null;
        latest: { route: string | null; traceId: string | null; user: string | null; at: string } | null;
    };
    events: EventRow[];
    activities: Array<{ id: number; label: string; actor: string | null; note: string | null; at: string | null }>;
    assignees: Option[];
    snoozeOptions: Option[];
    trackers: Option[];
    canUpdate: boolean;
};
const { t, number, dateTime } = useT();
const route = useRoute();
const { data } = await useApi<IssuePage>(() => `/projects/${route.params.project}/monitoring/issues/${route.params.issue}`);
const project = computed(() => data.value.overview.project);
const issue = computed(() => data.value.issue);
const base = computed(() => `/api/app/projects/${project.value.id}/monitoring/issues/${issue.value.id}`);
const assignee = ref<string | null>(issue.value.assigneeId ?? '');
const tracker = ref<string | null>(data.value.trackers[0]?.value ?? null);
const snooze = ref<string | null>(data.value.snoozeOptions[0]?.value ?? null);
const frames = computed(() => parseStackTrace(issue.value.details));
const allFrames = ref(false);
const shownFrames = computed(() => (allFrames.value || !frames.value.some((frame) => frame.app) ? frames.value : frames.value.filter((frame) => frame.app)));
const hours = computed(() => issue.value.trend.map((value, index) => ({ label: `${issue.value.trend.length - index}h`, value })));
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="issue.title" :description="`${issue.location ?? t('Unknown location')} · ${issue.statusLabel}`">
            <template v-if="data.canUpdate" #actions>
                <template v-if="issue.open">
                    <FormDialog id="resolve-issue" :title="t('Resolve this issue?')" :description="t('A new occurrence reopens it.')" :action="base" method="PATCH" :submit="t('Resolve')">
                        <template #trigger="{ open }"><AcmeBtn variant="primary" size="sm" icon="check" @click="open">{{ t('Resolve') }}</AcmeBtn></template>
                        <input type="hidden" name="action" value="resolve"><input type="hidden" name="version" :value="issue.version">
                        <TextareaField id="resolve-note" name="note" :label="t('Note (optional)')" rows="2" maxlength="1000" />
                    </FormDialog>
                    <FormDialog id="snooze-issue" :title="t('Snooze this issue')" :description="t('It’s hidden from open issues until then, unless it happens again.')" :action="base" method="PATCH" :submit="t('Snooze')">
                        <template #trigger="{ open }"><AcmeBtn size="sm" icon="clock" @click="open">{{ t('Snooze') }}</AcmeBtn></template>
                        <input type="hidden" name="action" value="snooze"><input type="hidden" name="version" :value="issue.version">
                        <div class="grid gap-4">
                            <SelectField id="snooze-minutes" v-model="snooze" name="snooze_minutes" :label="t('Snooze for')" :options="data.snoozeOptions" />
                            <TextareaField id="snooze-note" name="note" :label="t('Note (optional)')" rows="2" maxlength="1000" />
                        </div>
                    </FormDialog>
                    <FormDialog id="ignore-issue" :title="t('Ignore this issue?')" :description="t('It stays out of open issues and digests, even when it happens again.')" :action="base" method="PATCH" :submit="t('Ignore')">
                        <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Ignore') }}</AcmeBtn></template>
                        <input type="hidden" name="action" value="ignore"><input type="hidden" name="version" :value="issue.version">
                        <TextareaField id="ignore-note" name="note" :label="t('Note (optional)')" rows="2" maxlength="1000" />
                    </FormDialog>
                </template>
                <ApiForm v-else :action="base" method="PATCH">
                    <input type="hidden" name="action" value="reopen"><input type="hidden" name="version" :value="issue.version">
                    <SubmitButton variant="secondary" size="sm">{{ t('Reopen') }}</SubmitButton>
                </ApiForm>
            </template>
        </ProjectHeader>

        <div class="space-y-6">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-2xl border border-line bg-surface p-4 shadow-card"><p class="text-xs text-muted">{{ t('Status') }}</p><p class="mt-2"><AcmeBadge :tone="acmeTone(issue.statusTone)" dot>{{ issue.statusLabel }}</AcmeBadge></p><p v-if="issue.snoozedUntil" class="mt-1 text-xs text-muted">{{ t('Snoozed until :time', { time: dateTime(issue.snoozedUntil) }) }}</p></div>
                <div class="rounded-2xl border border-line bg-surface p-4 shadow-card"><p class="text-xs text-muted">{{ t('Events') }}</p><p class="mt-1 text-2xl font-semibold tabular-nums text-ink">{{ number(issue.occurrences) }}</p></div>
                <div class="rounded-2xl border border-line bg-surface p-4 shadow-card"><p class="text-xs text-muted">{{ t('People') }}</p><p class="mt-1 text-2xl font-semibold tabular-nums text-ink">{{ number(issue.users) }}</p></div>
                <div class="rounded-2xl border border-line bg-surface p-4 shadow-card">
                    <p class="text-xs text-muted">{{ t('First seen') }}</p>
                    <p class="mt-1 font-medium text-ink">{{ issue.firstSeenAt ? dateTime(issue.firstSeenAt) : '—' }}</p>
                    <p v-if="issue.firstRelease" class="text-xs text-muted">{{ t('in release') }} <span class="font-mono">{{ issue.firstRelease }}</span></p>
                </div>
                <div class="rounded-2xl border border-line bg-surface p-4 shadow-card"><p class="text-xs text-muted">{{ t('Assigned to') }}</p><p class="mt-1 font-medium text-ink">{{ issue.assignee ?? t('Nobody yet') }}</p></div>
            </div>

            <div class="grid items-start gap-6 xl:grid-cols-3">
            <div class="grid min-w-0 gap-6 xl:col-span-2">
                <AcmeCard :title="t('Events, last 12 hours')">
                    <AcmeBarChart :data="hours" :label="t('Events per hour')" :height="160" highlight="max" />
                </AcmeCard>

                <AcmeCard v-if="frames.length > 0" :title="t('Stack trace')">
                    <template #action>
                        <label class="flex items-center gap-2 text-sm text-muted"><input v-model="allFrames" type="checkbox" class="size-4 accent-[var(--ui-primary)]">{{ t('Show library frames') }}</label>
                    </template>
                    <ol class="space-y-2">
                        <li v-for="(frame, index) in shownFrames" :key="index" :class="['overflow-hidden rounded-xl border border-line', !frame.app && 'opacity-70']">
                            <p class="flex flex-wrap items-center gap-2 bg-black/[.03] px-4 py-2 font-mono text-xs dark:bg-white/[.04]">
                                <b class="font-medium text-ink">{{ frame.fn }}</b>
                                <span class="break-all text-muted">{{ frame.file }}<template v-if="frame.line !== null">:{{ frame.line }}</template></span>
                                <AcmeBadge v-if="frame.app" tone="blue" class="ml-auto">{{ t('Your code') }}</AcmeBadge>
                            </p>
                        </li>
                    </ol>
                </AcmeCard>
                <AcmeCard v-else-if="issue.details" :title="t('Details')">
                    <CodeBlock :code="issue.details" class="max-h-96 overflow-auto whitespace-pre-wrap break-all text-xs" />
                </AcmeCard>

                <div class="grid gap-6 lg:grid-cols-2">
                    <AcmeCard :title="t('Where it happens')">
                        <AcmeBarList v-if="issue.where.length > 0" :label="t('Route')" :items="issue.where" :value-label="t('Share')" :format="(value: number) => `${value}%`" />
                        <p v-else class="text-sm text-muted">{{ t('No occurrences in the last week.') }}</p>
                    </AcmeCard>
                    <AcmeCard :title="t('Latest event')">
                        <dl v-if="issue.latest" class="grid grid-cols-[6rem_1fr] gap-y-2 text-sm">
                            <dt class="text-muted">{{ t('When') }}</dt><dd class="text-ink"><RelativeTime :at="issue.latest.at" /></dd>
                            <dt class="text-muted">{{ t('Route') }}</dt><dd class="truncate font-mono text-xs text-ink">{{ issue.latest.route ?? '—' }}</dd>
                            <dt class="text-muted">{{ t('Trace') }}</dt>
                            <dd><NuxtLink v-if="issue.latest.traceId" :to="`/projects/${project.id}/monitoring/traces/${issue.latest.traceId}`" class="font-mono text-xs text-accent hover:underline">{{ issue.latest.traceId.slice(0, 12) }}</NuxtLink><span v-else class="text-muted">—</span></dd>
                            <dt class="text-muted">{{ t('Person') }}</dt><dd class="truncate text-ink">{{ issue.latest.user ? t('Signed in · :id', { id: issue.latest.user.slice(0, 10) }) : t('Not signed in') }}</dd>
                        </dl>
                        <p v-else class="text-sm text-muted">{{ t('No stored occurrences. Older ones may have passed the retention period.') }}</p>
                    </AcmeCard>
                </div>

                <AcmeCard :title="t('Recent occurrences')" :padded="false">
                    <DataTable :caption="t('Recent occurrences')" :framed="false">
                        <template #head>
                            <tr><th scope="col">{{ t('When') }}</th><th scope="col">{{ t('Environment') }}</th><th scope="col">{{ t('Event') }}</th></tr>
                        </template>
                        <tr v-for="event in data.events" :key="event.id">
                            <td class="whitespace-nowrap"><NuxtLink :to="`/projects/${project.id}/monitoring/events/${event.id}`" class="font-medium text-ink hover:underline">{{ dateTime(event.occurredAt) }}</NuxtLink></td>
                            <td class="text-muted">{{ event.environment ?? '—' }}</td>
                            <td class="break-all font-mono text-xs">{{ event.name }}</td>
                        </tr>
                        <tr v-if="data.events.length === 0"><td colspan="3" class="py-8 text-center text-muted">{{ t('No stored occurrences. Older ones may have passed the retention period.') }}</td></tr>
                    </DataTable>
                </AcmeCard>

                <AcmeCard :title="t('Activity')">
                    <AcmeTimeline v-if="data.activities.length > 0" :items="data.activities.map((activity) => ({ title: activity.label, time: [activity.at ? dateTime(activity.at) : null, activity.actor ?? t('Automatic')].filter(Boolean).join(' · '), body: activity.note ?? undefined }))" />
                    <p v-else class="text-sm text-muted">{{ t('No activity yet.') }}</p>
                </AcmeCard>
            </div>

            <aside class="space-y-6">
                <AcmeCard :title="t('Details')">
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-muted">{{ t('Severity') }}</dt><dd><AcmeBadge :tone="issue.severity === 'critical' || issue.severity === 'error' ? 'red' : 'gray'">{{ issue.severityLabel }}</AcmeBadge></dd></div>
                        <div v-if="issue.environment" class="flex justify-between gap-3"><dt class="text-muted">{{ t('First seen in') }}</dt><dd class="text-ink">{{ issue.environment }}</dd></div>
                        <div v-if="issue.ticketUrl" class="flex justify-between gap-3"><dt class="text-muted">{{ t('Ticket') }}</dt><dd><a :href="issue.ticketUrl" class="font-medium text-ink underline" rel="noopener noreferrer" target="_blank">{{ issue.ticketKey }}</a></dd></div>
                    </dl>
                    <ApiForm v-if="data.canUpdate" :action="base" method="PATCH" class="mt-4 flex items-end gap-2">
                        <input type="hidden" name="action" value="assign"><input type="hidden" name="version" :value="issue.version">
                        <SelectField id="issue-assignee" v-model="assignee" name="assignee_id" :label="t('Assigned to')" :placeholder="t('No one')" :options="data.assignees" class="flex-1" />
                        <SubmitButton variant="secondary" size="sm">{{ t('Save') }}</SubmitButton>
                    </ApiForm>
                </AcmeCard>
                <AcmeCard v-if="data.canUpdate && !issue.ticketUrl" :title="t('Ticket')">
                    <p v-if="data.trackers.length === 0" class="text-sm text-muted">
                        {{ t('File tickets in GitHub Issues, Linear or Jira: connect a tracker on') }}
                        <NuxtLink :to="`/projects/${project.id}/monitoring/setup#trackers`" class="font-medium text-ink underline">{{ t('the Setup page') }}</NuxtLink>.
                    </p>
                    <ApiForm v-else :action="`${base}/ticket`" class="grid gap-3">
                        <SelectField id="ticket-tracker" v-model="tracker" name="tracker" :label="t('Create a ticket in')" :options="data.trackers" />
                        <SubmitButton variant="secondary" size="sm">{{ t('Create ticket') }}</SubmitButton>
                    </ApiForm>
                </AcmeCard>
            </aside>
            </div>
        </div>
    </div>
</template>
