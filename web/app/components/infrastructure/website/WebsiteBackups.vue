<script setup lang="ts">
import type { WebsitePage } from '~/types/infrastructure';

/** A website's backups: backing up now, schedules, and restoring or checking a backup. */
const props = defineProps<{ page: WebsitePage; base: string }>();
const { t, tc, dateTime, locale } = useT();
const bytes = useFileSize();
const project = computed(() => props.page.overview.project.id);
const destination = ref<string | null>(props.page.backupDestinations[0]?.value ?? null);
const frequencies = computed(() => [{ value: 'daily', label: t('Every day') }, { value: 'weekly', label: t('Every week') }]);
/** The name of a day of the week, Sunday being 0. */
const dayName = (day: number) => new Intl.DateTimeFormat(locale.value, { weekday: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(2023, 0, 1 + day)));
const weekdays = computed(() => [0, 1, 2, 3, 4, 5, 6].map((day) => ({ value: String(day), label: dayName(day) })));
const restoreStatuses = computed<Record<string, string>>(() => ({ queued: t('queued'), running: t('running'), succeeded: t('succeeded'), failed: t('failed') }));
const busy = computed(() => props.page.backups.some((backup) => ['queued', 'running'].includes(backup.status)
    || ['queued', 'running'].includes(backup.restore?.status ?? '') || ['queued', 'running'].includes(backup.verification?.status ?? '')));
let timer: number | undefined;

// While a backup, restore or check runs, look again every few seconds.
watch(busy, (following) => {
    window.clearInterval(timer);
    timer = following ? window.setInterval(() => refreshNuxtData(), 5000) : undefined;
}, { immediate: import.meta.client });
onBeforeUnmount(() => window.clearInterval(timer));
</script>

<template>
    <SettingsSection id="backups" :title="t('Backups')" :description="t('The database, .env file and shared storage, sent with restic to a backup destination. Times are UTC.')">
        <div class="grid gap-4 p-4 sm:p-6">
            <Alert v-if="page.canManage && !page.canBackUp" tone="info">{{ t('Managed backups come with the Pro Deploy plan and above. Backups already taken can still be restored.') }}</Alert>
            <p v-else-if="page.canBackUp && page.backupDestinations.length === 0" class="text-sm text-muted">
                {{ t('Add a backup destination first.') }} <NuxtLink :to="`/projects/${project}/infrastructure/backups`" class="font-bold text-primary hover:underline">{{ t('Backup destinations') }}</NuxtLink>
            </p>

            <ul v-if="page.schedules.length > 0" class="divide-y divide-line text-sm">
                <li v-for="schedule in page.schedules" :key="schedule.id" class="flex flex-wrap items-center justify-between gap-3 py-2">
                    <span>
                        {{ schedule.frequency === 'weekly' ? t('Every :day at :time', { day: dayName(schedule.weekday), time: schedule.time }) : t('Every day at :time', { time: schedule.time }) }}
                        → {{ schedule.destination }}<template v-if="schedule.secondaryDestination"> {{ t('and :destination', { destination: schedule.secondaryDestination }) }}</template>
                        · {{ tc('keeps :count snapshot|keeps :count snapshots', schedule.retention, { count: schedule.retention }) }}<template v-if="schedule.monthlyDrill"> · {{ t('monthly restore drill') }}</template>
                    </span>
                    <ApiForm v-if="page.canManage" :action="`${base}/backup-schedules/${schedule.id}`" method="DELETE"><SubmitButton variant="quiet" size="sm">{{ t('Remove') }}</SubmitButton></ApiForm>
                </li>
            </ul>

            <div v-if="page.canBackUp && page.backupDestinations.length > 0" class="flex flex-wrap items-end gap-3 rounded-panel border border-line bg-surface-muted p-4">
                <ApiForm :action="`${base}/backups`" class="flex flex-wrap items-end gap-3">
                    <SelectField id="backup-now-destination" v-model="destination" name="backup_destination_id" :label="t('Back up now to')" :options="page.backupDestinations" />
                    <SubmitButton variant="secondary" :disabled="page.website.status !== 'active'">{{ t('Back up now') }}</SubmitButton>
                </ApiForm>
                <FormDialog
                    id="add-backup-schedule"
                    :title="t('Schedule backups')"
                    :description="t('Back the database and files up on a cron schedule, keeping as many copies as you choose.')"
                    :action="`${base}/backup-schedules`"
                    :submit="t('Save schedule')"
                    size="wide"
                >
                    <template #trigger="{ open }"><UiButton variant="quiet" @click="open">{{ t('Schedule backups') }}</UiButton></template>
                    <div class="grid items-start gap-4 sm:grid-cols-2">
                        <SelectField id="schedule-destination" name="backup_destination_id" :label="t('Schedule backups to')" :options="page.backupDestinations" />
                        <SelectField id="schedule-frequency" name="frequency" :label="t('How often')" :options="frequencies" />
                        <SelectField id="schedule-weekday" name="weekday" :label="t('Day (weekly)')" :options="weekdays" />
                        <InputField id="schedule-time" name="run_at" type="time" :label="t('Time (UTC)')" model-value="02:00" required />
                        <InputField id="schedule-retention" name="retention_count" type="number" min="1" max="365" :label="t('Snapshots to keep')" model-value="14" required />
                        <SelectField
                            id="schedule-secondary"
                            name="secondary_destination_id"
                            :label="t('Also copy to (optional)')"
                            :placeholder="t('Nowhere else')"
                            :options="page.backupDestinations"
                            :description="t('A second destination, such as another provider or region, for a copy that survives the first one being lost.')"
                        />
                        <div class="sm:col-span-2">
                            <CheckboxField
                                id="schedule-drill"
                                name="monthly_drill"
                                unchecked-value="0"
                                :label="t('Monthly restore drill')"
                                checked
                                :description="t('Once a month, the latest backup is restored into a scratch area on the server and checked; owners are emailed if it fails.')"
                            />
                        </div>
                    </div>
                </FormDialog>
            </div>

            <p v-if="page.backups.length === 0" class="text-sm text-muted">{{ t('No backups yet.') }}</p>
            <ul v-else class="divide-y divide-line">
                <li v-for="backup in page.backups" :key="backup.id" class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div class="min-w-0 text-sm">
                        <p class="flex flex-wrap items-center gap-2">
                            <span class="font-bold text-ink">{{ backup.createdAt ? dateTime(backup.createdAt) : '—' }}</span>
                            <BackupStatusBadge :status="backup.status" />
                            <span class="text-xs text-muted">
                                {{ backup.destination }}<template v-if="backup.sizeBytes !== null"> · {{ bytes(backup.sizeBytes) }}</template><template v-if="backup.scheduled"> · {{ t('scheduled') }}</template>
                                <template v-if="backup.secondaryStatus === 'copied'"> · {{ t('second copy made') }}</template>
                                <template v-else-if="backup.secondaryStatus === 'failed'"> · <span class="text-danger">{{ t('second copy failed') }}</span></template>
                            </span>
                        </p>
                        <p v-if="backup.error" class="text-xs text-danger">{{ backup.error }}</p>
                        <p v-if="backup.restore" class="text-xs text-muted">
                            {{ t('Restore:') }} {{ restoreStatuses[backup.restore.status] ?? backup.restore.status }}<template v-if="backup.restore.error"> · <span class="text-danger">{{ backup.restore.error }}</span></template>
                        </p>
                        <p v-if="backup.verification" class="text-xs text-muted">
                            {{ t('Verification:') }} {{ restoreStatuses[backup.verification.status] ?? backup.verification.status }}<template v-if="backup.verification.error"> · <span class="text-danger">{{ backup.verification.error }}</span></template>
                        </p>
                    </div>
                    <div v-if="backup.restorable" class="flex gap-1">
                        <ApiForm v-if="page.canBackUp" :action="`${base}/backups/${backup.id}/verify`"><SubmitButton variant="quiet" size="sm">{{ t('Verify') }}</SubmitButton></ApiForm>
                        <FormDialog
                            v-if="page.canManage"
                            :id="`restore-backup-${backup.id}`"
                            :title="t('Restore this backup?')"
                            :description="t('The live database, .env file and shared storage are replaced with the backup from :date. The website is in maintenance mode meanwhile, and put back as it was if any step fails.', { date: backup.createdAt ? dateTime(backup.createdAt) : '—' })"
                            :action="`${base}/backups/${backup.id}/restore`"
                            :submit="t('Restore')"
                            submit-variant="danger"
                        >
                            <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Restore') }}</UiButton></template>
                        </FormDialog>
                    </div>
                </li>
            </ul>
        </div>
    </SettingsSection>
</template>
