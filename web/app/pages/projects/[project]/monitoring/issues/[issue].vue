<script setup lang="ts">
import type { EventRow, IssueRow } from '~/types/monitoring';
import type { ProjectOverview } from '~/types/projects';
import type { Option } from '~/types/ui';

/** One issue: how often and where it happens, its recent occurrences and activity, and resolving, snoozing or assigning it. */
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
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="issue.title" :description="`${issue.location ?? t('Unknown location')} · ${issue.statusLabel}`">
            <template v-if="data.canUpdate" #actions>
                <template v-if="issue.open">
                    <FormDialog id="resolve-issue" :title="t('Resolve this issue?')" :description="t('A new occurrence reopens it.')" :action="base" method="PATCH" :submit="t('Resolve')">
                        <template #trigger="{ open }"><UiButton variant="primary" size="sm" @click="open"><Icon name="check" class="h-4 w-4" />{{ t('Resolve') }}</UiButton></template>
                        <input type="hidden" name="action" value="resolve"><input type="hidden" name="version" :value="issue.version">
                        <TextareaField id="resolve-note" name="note" :label="t('Note (optional)')" rows="2" maxlength="1000" />
                    </FormDialog>
                    <FormDialog id="snooze-issue" :title="t('Snooze this issue')" :description="t('It’s hidden from open issues until then, unless it happens again.')" :action="base" method="PATCH" :submit="t('Snooze')">
                        <template #trigger="{ open }"><UiButton size="sm" @click="open"><Icon name="clock" class="h-4 w-4" />{{ t('Snooze') }}</UiButton></template>
                        <input type="hidden" name="action" value="snooze"><input type="hidden" name="version" :value="issue.version">
                        <div class="grid gap-4">
                            <SelectField id="snooze-minutes" v-model="snooze" name="snooze_minutes" :label="t('Snooze for')" :options="data.snoozeOptions" />
                            <TextareaField id="snooze-note" name="note" :label="t('Note (optional)')" rows="2" maxlength="1000" />
                        </div>
                    </FormDialog>
                    <FormDialog id="ignore-issue" :title="t('Ignore this issue?')" :description="t('It stays out of open issues and digests, even when it happens again.')" :action="base" method="PATCH" :submit="t('Ignore')">
                        <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Ignore') }}</UiButton></template>
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

        <div class="grid items-start gap-6 xl:grid-cols-3">
            <div class="grid min-w-0 gap-6 xl:col-span-2">
                <div class="grid gap-4 sm:grid-cols-3">
                    <StatCard :label="t('Occurrences')" :value="number(issue.occurrences)" />
                    <StatCard :label="t('First seen')" :value="issue.firstSeenAt ? dateTime(issue.firstSeenAt) : '—'" />
                    <StatCard :label="t('Last seen')" :value="issue.lastSeenAt ? dateTime(issue.lastSeenAt) : '—'" />
                </div>
                <section v-if="issue.details" class="ui-card p-5" aria-labelledby="issue-details">
                    <h2 id="issue-details" class="text-sm font-bold text-ink">{{ t('Details') }}</h2>
                    <CodeBlock :code="issue.details" class="mt-3 max-h-96 overflow-auto whitespace-pre-wrap break-all text-xs" />
                </section>
                <DataTable :caption="t('Recent occurrences')">
                    <template #head>
                        <tr><th scope="col">{{ t('When') }}</th><th scope="col">{{ t('Environment') }}</th><th scope="col">{{ t('Event') }}</th></tr>
                    </template>
                    <tr v-for="event in data.events" :key="event.id">
                        <td class="whitespace-nowrap"><NuxtLink :to="`/projects/${project.id}/monitoring/events/${event.id}`" class="text-primary hover:underline">{{ dateTime(event.occurredAt) }}</NuxtLink></td>
                        <td>{{ event.environment ?? '—' }}</td>
                        <td class="break-all">{{ event.name }}</td>
                    </tr>
                    <tr v-if="data.events.length === 0"><td colspan="3" class="py-8 text-center text-muted">{{ t('No stored occurrences. Older ones may have passed the retention period.') }}</td></tr>
                </DataTable>
                <section class="grid gap-3" aria-labelledby="activity-heading">
                    <h2 id="activity-heading" class="text-sm font-bold text-ink">{{ t('Activity') }}</h2>
                    <ol class="grid gap-3">
                        <li v-for="activity in data.activities" :key="activity.id" class="rounded-panel border border-line bg-surface p-4 text-sm">
                            <div class="flex flex-wrap justify-between gap-2">
                                <p class="font-semibold text-ink">{{ activity.label }}</p>
                                <RelativeTime v-if="activity.at" :at="activity.at" class="text-xs text-muted" />
                            </div>
                            <p class="mt-1 text-xs text-muted">{{ activity.actor ?? t('Automatic') }}</p>
                            <p v-if="activity.note" class="mt-2 whitespace-pre-wrap break-words">{{ activity.note }}</p>
                        </li>
                        <li v-if="data.activities.length === 0" class="text-sm text-muted">{{ t('No activity yet.') }}</li>
                    </ol>
                </section>
            </div>

            <aside class="order-first grid gap-5 xl:order-none">
                <div class="ui-card grid gap-2 p-4 text-sm">
                    <p class="flex flex-wrap gap-2">
                        <Badge :tone="issue.statusTone">{{ issue.statusLabel }}</Badge>
                        <Badge :tone="issue.severity === 'critical' ? 'danger' : 'neutral'">{{ issue.severityLabel }}</Badge>
                    </p>
                    <p v-if="issue.snoozedUntil" class="text-muted">{{ t('Snoozed until :time', { time: dateTime(issue.snoozedUntil) }) }}</p>
                    <p class="text-muted">{{ t('Assigned to: :name', { name: issue.assignee ?? t('no one') }) }}</p>
                    <p v-if="issue.environment" class="text-muted">{{ t('First seen in :environment', { environment: issue.environment }) }}</p>
                    <p v-if="issue.ticketUrl" class="text-muted">{{ t('Ticket:') }} <a :href="issue.ticketUrl" class="font-bold text-primary hover:underline" rel="noopener noreferrer" target="_blank">{{ issue.ticketKey }}</a></p>
                </div>
                <div v-if="data.canUpdate && !issue.ticketUrl" class="ui-card">
                    <p v-if="data.trackers.length === 0" class="p-4 text-sm text-muted">
                        {{ t('File tickets in GitHub Issues, Linear or Jira: connect a tracker on') }}
                        <NuxtLink :to="`/projects/${project.id}/monitoring/setup#trackers`" class="font-bold text-primary hover:underline">{{ t('the Setup page') }}</NuxtLink>.
                    </p>
                    <ApiForm v-else :action="`${base}/ticket`" class="grid gap-3 p-4">
                        <SelectField id="ticket-tracker" v-model="tracker" name="tracker" :label="t('Create a ticket in')" :options="data.trackers" />
                        <SubmitButton variant="secondary" size="sm">{{ t('Create ticket') }}</SubmitButton>
                    </ApiForm>
                </div>
                <ApiForm v-if="data.canUpdate" :action="base" method="PATCH" class="ui-card grid gap-3 p-4">
                    <input type="hidden" name="action" value="assign"><input type="hidden" name="version" :value="issue.version">
                    <SelectField id="issue-assignee" v-model="assignee" name="assignee_id" :label="t('Assigned to')" :placeholder="t('No one')" :options="data.assignees" />
                    <SubmitButton variant="secondary" size="sm">{{ t('Save') }}</SubmitButton>
                </ApiForm>
            </aside>
        </div>
    </div>
</template>
