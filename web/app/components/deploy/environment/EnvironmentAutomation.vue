<script setup lang="ts">
import type { EnvironmentPage } from '~/types/deploy';

/**
 * What runs by itself in an environment: public release notes, maintenance mode, hibernation, scheduled deploys,
 * scaling schedules and scheduled tasks.
 */
const props = defineProps<{ page: EnvironmentPage; base: string }>();
const { t, tc, number } = useT();
const environment = computed(() => props.page.environment);
const hibernateAfter = ref<string | null>(environment.value.hibernateAfterMinutes === null ? '' : String(environment.value.hibernateAfterMinutes));
const hibernation = computed(() => props.page.hibernationMinutes.map((minutes) => ({
    value: String(minutes),
    label: minutes >= 60
        ? tc(':count hour without requests|:count hours without requests', Math.floor(minutes / 60), { count: Math.floor(minutes / 60) })
        : tc(':count minute without requests|:count minutes without requests', minutes, { count: minutes }),
})));
const runStatuses = computed<Record<string, string>>(() => ({ succeeded: t('Succeeded'), failed: t('Failed'), running: t('Running'), queued: t('Queued') }));
</script>

<template>
    <div class="space-y-10">
        <SettingsSection
            id="release-notes"
            :title="t('Release notes')"
            :description="t('Each deploy’s notes are written from its commits (feat:, fix: and perf: prefixes are grouped; chores, docs and tests are left out), shown on the deploy and sent with Deploy live notifications. Publish them for your users at a public address.')"
        >
            <div class="grid gap-3 p-4 sm:p-6">
                <CodeBlock v-if="environment.releaseNotesUrl" :code="environment.releaseNotesUrl" class="whitespace-pre-wrap break-all" />
                <ApiForm v-if="page.canManage" :action="`${base}/release-notes`" :method="environment.releaseNotesUrl ? 'DELETE' : 'PUT'">
                    <SubmitButton :variant="environment.releaseNotesUrl ? 'quiet' : 'secondary'">{{ environment.releaseNotesUrl ? t('Take the public page down') : t('Publish release notes') }}</SubmitButton>
                </ApiForm>
            </div>
        </SettingsSection>

        <SettingsSection
            id="maintenance"
            :title="t('Maintenance mode')"
            :description="t('Show visitors a “back soon” page while you work, on every website this environment deploys to. It stays on through deploys and hibernation until you turn it off.')"
        >
            <div class="grid gap-4 p-4 sm:p-6">
                <Alert v-if="environment.maintenanceError" tone="danger">{{ t('The last change didn’t apply everywhere: :error', { error: environment.maintenanceError }) }}</Alert>
                <template v-if="environment.maintenanceAt">
                    <p class="flex flex-wrap items-center gap-2 text-sm">
                        <Badge tone="warning">{{ t('In maintenance') }}</Badge>
                        <span class="text-muted"><Rich :text="t('since :when')"><template #when><RelativeTime :at="environment.maintenanceAt" /></template></Rich></span>
                    </p>
                    <p class="text-sm text-muted">
                        {{ t('To see the site yourself, open any of its addresses with this path once; a cookie lets you in from then on:') }}
                        <code class="font-mono text-ink">/{{ environment.maintenanceSecret }}</code>
                    </p>
                </template>
                <p v-else class="text-sm"><Badge tone="success">{{ t('Live') }}</Badge></p>
                <ApiForm v-if="page.canManage" :action="`${base}/maintenance`" method="PUT">
                    <input type="hidden" name="down" :value="environment.maintenanceAt ? '0' : '1'">
                    <SubmitButton :variant="environment.maintenanceAt ? 'primary' : 'secondary'" size="sm">{{ environment.maintenanceAt ? t('Bring the websites back') : t('Turn on maintenance mode') }}</SubmitButton>
                </ApiForm>
            </div>
        </SettingsSection>

        <SettingsSection
            id="hibernation"
            :title="t('Hibernation')"
            :description="t('After a while without requests, Laravel apps go into maintenance mode and workers stop; the next request wakes them within a minute. Deploys and scaling wake them too.')"
        >
            <div class="grid gap-4 p-4 sm:p-6">
                <p class="flex flex-wrap items-center gap-2 text-sm">
                    <template v-if="environment.hibernatedAt">
                        <Badge tone="info">{{ t('Hibernating') }}</Badge>
                        <span class="text-muted"><Rich :text="t('since :when')"><template #when><RelativeTime :at="environment.hibernatedAt" /></template></Rich></span>
                    </template>
                    <template v-else>
                        <Badge tone="success">{{ t('Running') }}</Badge>
                        <span class="text-muted">
                            {{ tc(':count replica|:count replicas', environment.desiredReplicas, { count: environment.desiredReplicas }) }}<template v-if="environment.lastActivityAt"> · <Rich :text="t('last activity :when')"><template #when><RelativeTime :at="environment.lastActivityAt" /></template></Rich></template>
                        </span>
                    </template>
                </p>
                <template v-if="page.canManage">
                    <ApiForm :action="`${base}/hibernation`" method="PUT" class="flex flex-wrap items-end gap-3">
                        <SelectField
                            v-model="hibernateAfter"
                            name="hibernate_after_minutes"
                            :label="t('Hibernate after')"
                            :placeholder="t('Never')"
                            :options="hibernation"
                            :disabled="!page.plan.hibernation"
                            :description="page.plan.hibernation ? undefined : t('Hibernation comes with the Starter Deploy plan and above.')"
                        />
                        <SubmitButton variant="secondary" :disabled="!page.plan.hibernation">{{ t('Save') }}</SubmitButton>
                    </ApiForm>
                    <ApiForm :action="`${base}/runtime`">
                        <input type="hidden" name="state" :value="environment.hibernatedAt ? 'running' : 'hibernated'">
                        <SubmitButton variant="quiet" size="sm" :disabled="!environment.hibernatedAt && !page.plan.hibernation">{{ environment.hibernatedAt ? t('Wake now') : t('Hibernate now') }}</SubmitButton>
                    </ApiForm>
                </template>
            </div>
        </SettingsSection>

        <SettingsSection id="deploy-schedules" :title="t('Scheduled deploys')" :description="t('Deploy this environment’s repositories on a schedule, through its approval, lock and window.')">
            <div class="grid gap-4 p-4 sm:p-6">
                <div v-for="schedule in page.deploySchedules" :key="schedule.id" class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <span>
                        <span class="font-bold">{{ schedule.name }}</span> <span class="font-mono text-xs text-muted">{{ schedule.cron }} · {{ schedule.timezone }}</span>
                        <span class="block text-xs text-muted">
                            <Rich v-if="schedule.nextRunAt" :text="t('Next :when')"><template #when><RelativeTime :at="schedule.nextRunAt" /></template></Rich>
                            <template v-if="schedule.lastResult"> · {{ t('Last: :result', { result: schedule.lastResult }) }}</template>
                        </span>
                    </span>
                    <ApiForm v-if="page.canManage" :action="`${base}/deployment-schedules/${schedule.id}`" method="DELETE"><SubmitButton variant="quiet" size="sm">{{ t('Remove') }}</SubmitButton></ApiForm>
                </div>
                <FormDialog
                    v-if="page.canManage && page.plan.scheduled"
                    id="add-deploy-schedule"
                    :title="t('Add a deploy schedule')"
                    :description="t('Deploy the latest commit on a cron schedule, in the environment’s time zone.')"
                    :action="`${base}/deployment-schedules`"
                    :submit="t('Add scheduled deploy')"
                    size="wide"
                >
                    <template #trigger="{ open }"><div><UiButton @click="open">{{ t('Add a deploy schedule') }}</UiButton></div></template>
                    <div class="grid items-start gap-4 sm:grid-cols-3">
                        <InputField id="deploy-schedule-name" name="name" :label="t('Name')" placeholder="Nightly" maxlength="100" required autofocus />
                        <InputField id="deploy-schedule-cron" name="cron_expression" :label="t('Cron')" placeholder="0 3 * * *" maxlength="100" required class="font-mono" />
                        <InputField id="deploy-schedule-timezone" name="timezone" :label="t('Time zone')" :model-value="environment.timezone" maxlength="64" required />
                    </div>
                </FormDialog>
                <p v-else-if="!page.plan.scheduled" class="text-sm text-muted">{{ t('Scheduled deploys and tasks come with the Pro Deploy plan and above.') }}</p>
            </div>
        </SettingsSection>

        <SettingsSection
            id="scaling-schedules"
            :title="t('Scaling schedules')"
            :description="t('Run more or fewer worker replicas at set times, between :min and :max.', { min: environment.minimumReplicas, max: environment.maximumReplicas })"
        >
            <div class="grid gap-4 p-4 sm:p-6">
                <div v-for="schedule in page.scalingSchedules" :key="schedule.id" class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <span>
                        <span class="font-bold">{{ schedule.name }}</span> <span class="text-xs text-muted">×{{ schedule.replicas }}</span>
                        <span class="font-mono text-xs text-muted"> {{ schedule.cron }} · {{ schedule.timezone }}</span>
                        <span v-if="schedule.nextRunAt" class="block text-xs text-muted"><Rich :text="t('Next :when')"><template #when><RelativeTime :at="schedule.nextRunAt" /></template></Rich></span>
                    </span>
                    <ApiForm v-if="page.canManage" :action="`${base}/scaling-schedules/${schedule.id}`" method="DELETE"><SubmitButton variant="quiet" size="sm">{{ t('Remove') }}</SubmitButton></ApiForm>
                </div>
                <FormDialog
                    v-if="page.canManage && page.plan.scaling"
                    id="add-scaling-schedule"
                    :title="t('Add a scaling schedule')"
                    :description="t('Change how many copies run at set times.')"
                    :action="`${base}/scaling-schedules`"
                    :submit="t('Add scaling schedule')"
                    size="wide"
                >
                    <template #trigger="{ open }"><div><UiButton @click="open">{{ t('Add a scaling schedule') }}</UiButton></div></template>
                    <div class="grid items-start gap-4 sm:grid-cols-2">
                        <InputField id="scaling-schedule-name" name="name" :label="t('Name')" placeholder="Weekday mornings" maxlength="100" required autofocus />
                        <InputField
                            id="scaling-schedule-replicas"
                            name="replicas"
                            type="number"
                            :min="environment.minimumReplicas"
                            :max="environment.maximumReplicas"
                            :label="t('Replicas')"
                            :model-value="String(environment.maximumReplicas)"
                            required
                        />
                        <InputField id="scaling-schedule-cron" name="cron_expression" :label="t('Cron')" placeholder="0 8 * * 1-5" maxlength="100" required class="font-mono" />
                        <InputField id="scaling-schedule-timezone" name="timezone" :label="t('Time zone')" :model-value="environment.timezone" maxlength="64" required />
                    </div>
                </FormDialog>
                <p v-else-if="!page.plan.scaling" class="text-sm text-muted">{{ t('Scaling comes with the Business Deploy plan and above.') }}</p>
            </div>
        </SettingsSection>

        <SettingsSection id="tasks" :title="t('Scheduled tasks')" :description="t('Commands run in a website’s current release, as www-data with its .env, under a timeout.')">
            <div class="grid gap-4 p-4 sm:p-6">
                <div v-for="task in page.tasks" :key="task.id" class="space-y-2 border-b border-line pb-4 text-sm last:border-0 last:pb-0">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <span>
                            <span class="font-bold">{{ task.name }}</span>
                            <Badge v-if="task.lastStatus" :tone="task.lastStatus === 'succeeded' ? 'success' : 'danger'" class="ml-2">{{ task.lastStatus === 'succeeded' ? t('Succeeded') : t('Failed') }}</Badge>
                            <span class="block font-mono text-xs text-muted">
                                {{ task.cron }} · {{ task.timezone }} · {{ task.website }} · {{ tc(':count second timeout|:count seconds timeout', task.timeoutSeconds, { count: task.timeoutSeconds }) }}
                            </span>
                        </span>
                        <div v-if="page.canManage" class="flex gap-2">
                            <ApiForm :action="`${base}/tasks/${task.id}/run`"><SubmitButton variant="secondary" size="sm">{{ t('Run now') }}</SubmitButton></ApiForm>
                            <ApiForm :action="`${base}/tasks/${task.id}`" method="DELETE" :confirm="t('Remove :name?', { name: task.name })"><SubmitButton variant="quiet" size="sm">{{ t('Remove') }}</SubmitButton></ApiForm>
                        </div>
                    </div>
                    <ul v-if="task.runs.length > 0" class="grid gap-0.5 text-xs text-muted">
                        <li v-for="run in task.runs" :key="run.id">
                            <RelativeTime v-if="run.createdAt" :at="run.createdAt" /> · {{ runStatuses[run.status] ?? run.status }}<template v-if="run.durationMs !== null"> · {{ number(Math.round(run.durationMs / 100) / 10) }} s</template>
                            <template v-if="run.requester"> · {{ t('by :name', { name: run.requester }) }}</template>
                            <template v-if="page.canManage && !run.active"> · <a :href="`${base}/tasks/${task.id}/runs/${run.id}`" class="text-primary hover:underline" target="_blank" rel="noopener">{{ t('Output') }}</a></template>
                        </li>
                    </ul>
                </div>
                <template v-if="page.canManage && page.plan.scheduled">
                    <p v-if="page.taskWebsites.length === 0" class="text-sm text-muted">{{ t('Connect a repository that deploys this environment to a website before adding tasks.') }}</p>
                    <FormDialog
                        v-else
                        id="add-task"
                        :title="t('Add a scheduled task')"
                        :description="t('A command that runs on a cron schedule, with its output kept.')"
                        :action="`${base}/tasks`"
                        :submit="t('Add task')"
                        size="wide"
                    >
                        <template #trigger="{ open }"><div><UiButton @click="open">{{ t('Add a scheduled task') }}</UiButton></div></template>
                        <div class="grid items-start gap-4 sm:grid-cols-3">
                            <InputField id="task-name" name="name" :label="t('Name')" placeholder="Prune reports" maxlength="100" required autofocus />
                            <SelectField id="task-website" name="website_id" :label="t('Runs in')" :options="page.taskWebsites" />
                            <InputField id="task-timeout" name="timeout_seconds" type="number" min="10" max="3600" :label="t('Timeout (seconds)')" model-value="300" required />
                            <div class="sm:col-span-3"><InputField id="task-command" name="command" :label="t('Command')" placeholder="php artisan reports:prune" maxlength="2000" required class="font-mono" /></div>
                            <InputField id="task-cron" name="cron_expression" :label="t('Cron')" placeholder="*/15 * * * *" maxlength="100" required class="font-mono" />
                            <InputField id="task-timezone" name="timezone" :label="t('Time zone')" :model-value="environment.timezone" maxlength="64" required />
                            <div class="grid gap-1 self-end">
                                <CheckboxField id="task-overlap" name="without_overlapping" :label="t('Skip while the last run is going')" checked />
                                <CheckboxField id="task-alert" name="alert_on_failure" :label="t('Tell us when it fails')" checked />
                            </div>
                        </div>
                    </FormDialog>
                </template>
            </div>
        </SettingsSection>
    </div>
</template>
