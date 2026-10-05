<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';
import type { Option } from '~/types/ui';

/**
 * One incident (the Acme theme's incident page): a deploy that likely caused it, what failed, its timeline with notes,
 * acknowledging, resolving and assigning it, who was told, and its post-mortem.
 */
definePageMeta({ layout: 'app', service: 'monitoring' });
type IncidentPage = {
    overview: ProjectOverview;
    incident: {
        id: number;
        title: string;
        status: string;
        statusLabel: string;
        version: number;
        observation: string;
        details: string[];
        configuration: string;
        openedAt: string;
        lastBreachedAt: string;
        resolvedAt: string | null;
        acknowledgedAt: string | null;
        acknowledgedBy: string | null;
        assigneeId: number | null;
        assignee: string | null;
        monitorId: number | null;
        postmortem: Record<string, string> | null;
    };
    sections: Record<string, string>;
    draft: Record<string, string>;
    activities: Array<{ id: number; action: string; label: string; actor: string | null; note: string | null; at: string | null }>;
    assignees: Option[];
    statusPages: Option[];
    severities: Option[];
    report: { statusPageId: number; page: string; url: string | null; severity: string; title: string } | null;
    told: Array<{ name: string; type: string; status: string }>;
    likelyCause: { id: number; commitMessage: string | null; repository: string; minutes: number } | null;
    canRespond: boolean;
};
const { t, dateTime } = useT();
const labels = useDeployLabels();
const route = useRoute();
const { data } = await useApi<IncidentPage>(() => `/projects/${route.params.project}/monitoring/incidents/${route.params.incident}`);
const project = computed(() => data.value.overview.project);
const incident = computed(() => data.value.incident);
const base = computed(() => `/api/app/projects/${project.value.id}/monitoring/incidents/${incident.value.id}`);
const assignee = ref<string | null>(incident.value.assigneeId === null ? '' : String(incident.value.assigneeId));
const hints = computed<Record<string, string>>(() => ({
    summary: t('A few sentences anyone can follow.'),
    impact: t('Who was affected, how, and for how long.'),
    root_cause: t('Why it happened, not who.'),
    resolution: t('What was done to fix it.'),
}));
const canPublish = computed(() => incident.value.postmortem !== null && incident.value.resolvedAt !== null && data.value.statusPages.length > 0);
const icons: Record<string, string> = {
    opened: 'alert', monitor_failed: 'alert', acknowledge: 'check', note: 'message', assign: 'user', recovered: 'checkCircle', resolved_by_hand: 'checkCircle',
    postmortem_saved: 'fileText', postmortem_published: 'send', self_healed: 'refresh', monitor_paused: 'pause', monitor_resumed: 'play', rule_paused: 'pause', rule_resumed: 'play',
};
const red = 'bg-rose-500 text-white';
const green = 'bg-emerald-500 text-white';
const tones: Record<string, string> = { opened: red, monitor_failed: red, acknowledge: 'bg-amber-400 text-white', recovered: green, resolved_by_hand: green };
const timeline = computed(() => [...data.value.activities].reverse().map((activity) => ({
    title: activity.label,
    time: [activity.at ? dateTime(activity.at) : null, activity.actor ?? t('Automatic')].filter(Boolean).join(' · '),
    body: activity.note ?? undefined,
    icon: icons[activity.action] ?? 'circle',
    tone: tones[activity.action],
})));
const lasted = computed(() => labels.duration(Math.round(((incident.value.resolvedAt ? Date.parse(incident.value.resolvedAt) : Date.now()) - Date.parse(incident.value.openedAt)) / 1000)));
const statusTone = computed(() => (incident.value.status === 'open' ? 'red' : incident.value.status === 'acknowledged' ? 'amber' : 'green'));
const channelIcon = (type: string) => (/mail/i.test(type) ? 'mail' : /slack|discord|teams/i.test(type) ? 'message' : /sms|phone|twilio/i.test(type) ? 'phone' : 'globe');
let timer: number | undefined;

// The timeline keeps itself current while the incident is open.
onMounted(() => (timer = window.setInterval(() => incident.value.status !== 'resolved' && refreshNuxtData(), 5000)));
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <div>
        <ProjectHeader :overview="data.overview" :title="`#${incident.id} · ${incident.title}`" :description="incident.configuration">
            <template v-if="data.canRespond" #actions>
                <ApiForm v-if="incident.status === 'open'" :action="base" method="PATCH">
                    <input type="hidden" name="action" value="acknowledge">
                    <input type="hidden" name="version" :value="incident.version">
                    <SubmitButton variant="secondary">{{ t('Acknowledge') }}</SubmitButton>
                </ApiForm>
                <ApiForm v-if="incident.status !== 'resolved'" :action="base" method="PATCH" :confirm="t('Resolve this incident? If it’s still failing, the next failed check opens a new one.')">
                    <input type="hidden" name="action" value="resolve">
                    <input type="hidden" name="version" :value="incident.version">
                    <SubmitButton>{{ t('Resolve') }}</SubmitButton>
                </ApiForm>
                <AcmeBtn v-if="data.statusPages.length > 0" icon="send" :to="`/projects/${project.id}/monitoring/status-pages`">{{ t('Update status page') }}</AcmeBtn>
            </template>
        </ProjectHeader>

        <div class="grid items-start gap-6 xl:grid-cols-[1fr_20rem]">
            <div class="min-w-0 space-y-6">
                <AcmeCard v-if="data.likelyCause" :title="t('Likely cause')" :description="t('Something that changed shortly before the first failure.')">
                    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-amber-500/30 bg-amber-500/[.06] p-4 text-sm">
                        <AcmeIcon name="rocket" class="text-amber-600" />
                        <span class="flex-1 text-ink">
                            {{ t('Deploy #:id of :repository (“:message”) went live :minutes minutes before the first failure.', { id: data.likelyCause.id, repository: data.likelyCause.repository, message: data.likelyCause.commitMessage ?? '—', minutes: data.likelyCause.minutes }) }}
                        </span>
                        <AcmeBtn size="sm" :to="`/projects/${project.id}/deploy/builds/${data.likelyCause.id}`">{{ t('View deploy') }}</AcmeBtn>
                    </div>
                </AcmeCard>

                <AcmeCard :title="t('What happened')">
                    <div class="grid gap-2 text-sm">
                        <p class="text-ink">{{ incident.observation }}</p>
                        <p v-for="(line, index) in incident.details" :key="index" class="text-xs text-muted">{{ line }}</p>
                        <p class="text-muted">{{ t('Opened :opened · last failure :breached', { opened: dateTime(incident.openedAt), breached: dateTime(incident.lastBreachedAt) }) }}</p>
                        <p v-if="incident.acknowledgedAt" class="text-muted">{{ t('Acknowledged by :name at :time.', { name: incident.acknowledgedBy ?? t('a former member'), time: dateTime(incident.acknowledgedAt) }) }}</p>
                        <p v-if="incident.monitorId"><NuxtLink :to="`/projects/${project.id}/monitoring/monitors/${incident.monitorId}`" class="font-medium text-ink underline">{{ t('View the monitor and its checks') }}</NuxtLink></p>
                    </div>
                </AcmeCard>

                <AcmeCard :title="t('Timeline')">
                    <AcmeTimeline :items="timeline" />
                    <ApiForm v-if="data.canRespond" :action="base" method="PATCH" class="mt-5 flex items-end gap-2">
                        <input type="hidden" name="action" value="note">
                        <input type="hidden" name="version" :value="incident.version">
                        <InputField id="incident-note" name="note" :label="t('Add a note')" :placeholder="t('Add a note for the team…')" maxlength="1000" class="flex-1" />
                        <SubmitButton variant="secondary">{{ t('Add note') }}</SubmitButton>
                    </ApiForm>
                </AcmeCard>

                <AcmeCard :title="t('Post-mortem')" :description="t('Blameless and specific: what happened and what changes because of it.')">
                    <template v-if="data.canRespond" #action>
                        <div class="flex flex-wrap gap-2">
                            <FormDialog
                                id="postmortem"
                                :title="t('Post-mortem')"
                                :description="incident.postmortem ? t('Blameless and specific: what happened and what changes because of it.') : t('Drafted from the timeline, the alert and the deploys before it. Check each section and make it yours before saving.')"
                                :action="`${base}/postmortem`"
                                method="PUT"
                                :submit="t('Save post-mortem')"
                            >
                                <template #trigger="{ open }"><AcmeBtn size="sm" :icon="incident.postmortem ? 'edit' : 'sparkle'" @click="open">{{ incident.postmortem ? t('Edit post-mortem') : t('Write a post-mortem') }}</AcmeBtn></template>
                                <TextareaField
                                    v-for="(heading, key) in data.sections"
                                    :id="`postmortem-${key}`"
                                    :key="key"
                                    :name="String(key)"
                                    :label="heading"
                                    :model-value="incident.postmortem?.[key] ?? data.draft[key] ?? ''"
                                    rows="5"
                                    maxlength="5000"
                                    :description="hints[key] ?? t('The changes that stop it happening again, with owners.')"
                                />
                            </FormDialog>
                            <FormDialog
                                v-if="canPublish"
                                id="publish-postmortem"
                                :title="t('Publish to a status page')"
                                :description="t('Posts a resolved incident report with the summary, impact, root cause, resolution and follow-ups. Subscribers aren’t emailed.')"
                                :action="`${base}/postmortem/publish`"
                                :submit="t('Publish')"
                            >
                                <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ data.report ? t('Update status page report') : t('Publish to status page') }}</AcmeBtn></template>
                                <div class="grid items-start gap-4">
                                    <SelectField id="publish-page" name="status_page_id" :label="t('Status page')" :options="data.statusPages" :model-value="data.report ? String(data.report.statusPageId) : undefined" />
                                    <SelectField id="publish-severity" name="severity" :label="t('Severity')" :options="data.severities" :model-value="data.report?.severity ?? 'minor'" />
                                    <InputField id="publish-title" name="title" :label="t('Public title')" :model-value="data.report?.title ?? incident.title" maxlength="200" required />
                                </div>
                            </FormDialog>
                        </div>
                    </template>
                    <dl v-if="incident.postmortem" class="grid gap-3 text-sm">
                        <template v-for="(heading, key) in data.sections" :key="key">
                            <div v-if="incident.postmortem[key]"><dt class="font-medium text-ink">{{ heading }}</dt><dd class="mt-1 whitespace-pre-wrap break-words text-muted">{{ incident.postmortem[key] }}</dd></div>
                        </template>
                    </dl>
                    <p v-else class="text-sm text-muted">{{ t('Once it’s resolved, write up what happened, the impact, the root cause, how it was fixed and what happens next.') }}</p>
                    <p v-if="data.report" class="mt-3 text-xs text-muted">
                        {{ t('Published to :page.', { page: data.report.page }) }}
                        <a v-if="data.report.url" :href="data.report.url" class="font-medium text-ink underline" target="_blank" rel="noopener">{{ t('View') }}</a>
                    </p>
                </AcmeCard>
            </div>

            <aside class="space-y-6 xl:sticky xl:top-4">
                <AcmeCard :title="t('Details')">
                    <dl class="space-y-3 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-muted">{{ t('Status') }}</dt><dd><AcmeBadge :tone="statusTone" dot>{{ incident.statusLabel }}</AcmeBadge></dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-muted">{{ t('Opened') }}</dt><dd class="text-ink"><RelativeTime :at="incident.openedAt" /></dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-muted">{{ incident.resolvedAt ? t('Lasted') : t('Open for') }}</dt><dd class="text-ink">{{ lasted }}</dd></div>
                        <div v-if="!data.canRespond" class="flex justify-between gap-3"><dt class="text-muted">{{ t('Assigned to') }}</dt><dd class="text-ink">{{ incident.assignee ?? t('no one') }}</dd></div>
                    </dl>
                    <ApiForm v-if="data.canRespond" :action="base" method="PATCH" class="mt-4 flex items-end gap-2">
                        <input type="hidden" name="action" value="assign">
                        <input type="hidden" name="version" :value="incident.version">
                        <SelectField id="incident-assignee" v-model="assignee" name="assignee_id" :label="t('Assigned to')" :placeholder="t('No one')" :options="data.assignees" class="flex-1" />
                        <SubmitButton variant="secondary" size="sm">{{ t('Save') }}</SubmitButton>
                    </ApiForm>
                </AcmeCard>
                <AcmeCard :title="t('Who was told')">
                    <ul v-if="data.told.length > 0" class="space-y-2 text-sm">
                        <li v-for="(destination, index) in data.told" :key="index" class="flex items-center gap-2">
                            <AcmeIcon :name="channelIcon(destination.type)" :size="14" class="text-muted" />
                            <span class="min-w-0 flex-1 truncate text-ink">{{ destination.name }} <span class="text-muted">· {{ destination.type }}</span></span>
                            <AcmeBadge :tone="destination.status === 'accepted' ? 'green' : destination.status === 'failed' ? 'red' : 'gray'">{{ destination.status === 'accepted' ? t('Sent') : destination.status === 'failed' ? t('Failed') : t('Sending') }}</AcmeBadge>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted">{{ t('No alert destinations were told. Incidents still show up here.') }}</p>
                </AcmeCard>
            </aside>
        </div>
    </div>
</template>
