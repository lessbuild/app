<script setup lang="ts">
import type { ProjectOverview } from '~/types/projects';
import type { Option } from '~/types/ui';

/** One incident: what failed, its timeline, acknowledging, assigning and notes, and its post-mortem. */
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
    activities: Array<{ id: number; label: string; actor: string | null; note: string | null; at: string | null }>;
    assignees: Option[];
    statusPages: Option[];
    severities: Option[];
    report: { statusPageId: number; page: string; url: string | null; severity: string; title: string } | null;
    canRespond: boolean;
};
const { t, dateTime } = useT();
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
let timer: number | undefined;

// The timeline keeps itself current while the incident is open.
onMounted(() => (timer = window.setInterval(() => incident.value.status !== 'resolved' && refreshNuxtData(), 5000)));
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <div class="space-y-6">
        <ProjectHeader :overview="data.overview" :title="incident.title" :description="`#${incident.id} · ${incident.statusLabel}`" />
        <div class="grid items-start gap-6 xl:grid-cols-3">
            <div class="grid gap-6 xl:col-span-2">
                <section class="ui-card grid gap-3 p-5 text-sm">
                    <p class="flex flex-wrap items-center gap-2">
                        <Badge :tone="incident.status === 'open' ? 'danger' : incident.status === 'acknowledged' ? 'warning' : 'neutral'">{{ incident.statusLabel }}</Badge>
                        <span class="text-muted">{{ incident.observation }}</span>
                    </p>
                    <p v-for="(line, index) in incident.details" :key="index" class="text-xs text-muted">{{ line }}</p>
                    <p class="text-muted">{{ incident.configuration }}</p>
                    <p class="text-muted">{{ t('Opened :opened · last failure :breached', { opened: dateTime(incident.openedAt), breached: dateTime(incident.lastBreachedAt) }) }}</p>
                    <p v-if="incident.resolvedAt">{{ t(':status at :time.', { status: incident.statusLabel, time: dateTime(incident.resolvedAt) }) }}</p>
                    <p v-if="incident.acknowledgedAt">{{ t('Acknowledged by :name at :time.', { name: incident.acknowledgedBy ?? t('a former member'), time: dateTime(incident.acknowledgedAt) }) }}</p>
                    <p>{{ t('Assigned to: :name', { name: incident.assignee ?? t('no one') }) }}</p>
                    <p v-if="incident.monitorId"><NuxtLink :to="`/projects/${project.id}/monitoring/monitors/${incident.monitorId}`" class="font-bold text-primary hover:underline">{{ t('View the monitor and its checks') }}</NuxtLink></p>
                </section>

                <section class="ui-card grid gap-4 p-5" aria-labelledby="postmortem-heading">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 id="postmortem-heading" class="text-sm font-bold text-ink">{{ t('Post-mortem') }}</h2>
                        <div v-if="data.canRespond" class="flex flex-wrap gap-2">
                            <FormDialog
                                id="postmortem"
                                :title="t('Post-mortem')"
                                :description="incident.postmortem ? t('Blameless and specific: what happened and what changes because of it.') : t('Drafted from the timeline, the alert and the deploys before it. Check each section and make it yours before saving.')"
                                :action="`${base}/postmortem`"
                                method="PUT"
                                :submit="t('Save post-mortem')"
                                size="large"
                            >
                                <template #trigger="{ open }"><UiButton size="sm" @click="open">{{ incident.postmortem ? t('Edit post-mortem') : t('Write a post-mortem') }}</UiButton></template>
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
                                <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ data.report ? t('Update status page report') : t('Publish to status page') }}</UiButton></template>
                                <div class="grid items-start gap-5 sm:grid-cols-2">
                                    <SelectField id="publish-page" name="status_page_id" :label="t('Status page')" :options="data.statusPages" :model-value="data.report ? String(data.report.statusPageId) : undefined" />
                                    <SelectField id="publish-severity" name="severity" :label="t('Severity')" :options="data.severities" :model-value="data.report?.severity ?? 'minor'" />
                                    <div class="sm:col-span-2"><InputField id="publish-title" name="title" :label="t('Public title')" :model-value="data.report?.title ?? incident.title" maxlength="200" required /></div>
                                </div>
                            </FormDialog>
                        </div>
                    </div>
                    <dl v-if="incident.postmortem" class="grid gap-3 text-sm">
                        <template v-for="(heading, key) in data.sections" :key="key">
                            <div v-if="incident.postmortem[key]"><dt class="font-bold text-ink">{{ heading }}</dt><dd class="mt-1 whitespace-pre-wrap break-words text-muted">{{ incident.postmortem[key] }}</dd></div>
                        </template>
                    </dl>
                    <p v-else class="text-sm text-muted">{{ t('Once it’s resolved, write up what happened, the impact, the root cause, how it was fixed and what happens next.') }}</p>
                    <p v-if="data.report" class="text-xs text-muted">
                        {{ t('Published to :page.', { page: data.report.page }) }}
                        <a v-if="data.report.url" :href="data.report.url" class="font-bold text-primary underline" target="_blank" rel="noopener">{{ t('View') }}</a>
                    </p>
                </section>

                <section class="grid gap-3" aria-labelledby="timeline-heading">
                    <h2 id="timeline-heading" class="text-sm font-bold text-ink">{{ t('Timeline') }}</h2>
                    <ol class="grid gap-3">
                        <li v-for="activity in data.activities" :key="activity.id" class="rounded-panel border border-line bg-surface p-4">
                            <div class="flex flex-wrap justify-between gap-2">
                                <p class="text-sm font-semibold text-ink">{{ activity.label }}</p>
                                <time v-if="activity.at" class="text-xs text-muted" :datetime="activity.at">{{ dateTime(activity.at) }}</time>
                            </div>
                            <p class="mt-1 text-xs text-muted">{{ activity.actor ?? t('Automatic') }}</p>
                            <p v-if="activity.note" class="mt-2 whitespace-pre-wrap break-words text-sm">{{ activity.note }}</p>
                        </li>
                    </ol>
                </section>
            </div>

            <aside v-if="data.canRespond" class="order-first grid gap-5 xl:order-none">
                <ApiForm v-if="incident.status === 'open'" :action="base" method="PATCH">
                    <input type="hidden" name="action" value="acknowledge">
                    <input type="hidden" name="version" :value="incident.version">
                    <SubmitButton class="w-full">{{ t('Acknowledge') }}</SubmitButton>
                </ApiForm>
                <section class="ui-card">
                    <ApiForm :action="base" method="PATCH" class="grid gap-3 p-4">
                        <input type="hidden" name="action" value="assign">
                        <input type="hidden" name="version" :value="incident.version">
                        <SelectField v-model="assignee" name="assignee_id" :label="t('Assigned to')" :placeholder="t('No one')" :options="data.assignees" />
                        <SubmitButton variant="secondary" size="sm">{{ t('Save') }}</SubmitButton>
                    </ApiForm>
                </section>
                <section class="ui-card">
                    <ApiForm :action="base" method="PATCH" class="grid gap-3 p-4">
                        <input type="hidden" name="action" value="note">
                        <input type="hidden" name="version" :value="incident.version">
                        <TextareaField name="note" :label="t('Add a note')" rows="3" maxlength="1000" />
                        <SubmitButton variant="secondary" size="sm">{{ t('Add note') }}</SubmitButton>
                    </ApiForm>
                </section>
            </aside>
        </div>
    </div>
</template>
