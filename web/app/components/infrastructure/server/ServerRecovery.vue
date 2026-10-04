<script setup lang="ts">
import type { ServerPage } from '~/types/infrastructure';

/** Continuous backup of a database server, and restoring it to a moment in time. */
const props = defineProps<{ page: ServerPage; base: string }>();
const { t, dateTime } = useT();
const recovery = computed(() => props.page.recovery);
const postgres = computed(() => props.page.server.databaseEngine === 'postgres');
const destination = ref<string | null>(recovery.value?.plan ? String(recovery.value.plan.destinationId) : (recovery.value?.destinations[0]?.value ?? null));
</script>

<template>
    <div v-if="recovery" class="space-y-10">
        <SettingsSection
            id="continuous-backup"
            :title="t('Continuous backup')"
            :description="t('A daily base backup plus the :log, kept in a backup destination, so :engine can be restored to any moment in the window. Uses WAL-G.', { log: postgres ? t('write-ahead log (sent as it’s written)') : t('binary log (sent every five minutes)'), engine: postgres ? 'PostgreSQL' : 'MySQL' })"
        >
            <div class="grid gap-4 p-4 sm:p-6">
                <p v-if="recovery.plan" class="flex flex-wrap items-center gap-2 text-sm">
                    <Badge tone="success">{{ t('On') }}</Badge>
                    {{ t('Backing up to :destination, keeping :days days. Restorable from :from.', { destination: recovery.plan.destination, days: recovery.plan.retentionDays, from: dateTime(recovery.plan.earliest) }) }}
                    <span v-if="recovery.plan.setupStatus" class="text-muted">· {{ t('Setup: :status', { status: recovery.plan.setupStatus }) }}</span>
                </p>
                <template v-if="page.canRunCommands">
                    <p v-if="recovery.destinations.length === 0" class="text-sm text-muted">{{ t('Add an S3-compatible backup destination under Infrastructure → Backups first.') }}</p>
                    <ApiForm v-else :action="`${base}/database-recovery`" class="flex flex-wrap items-end gap-3">
                        <SelectField v-model="destination" name="backup_destination_id" :label="t('Destination')" :options="recovery.destinations" />
                        <InputField name="retention_days" type="number" min="1" max="35" :label="t('Keep (days)')" :model-value="String(recovery.plan?.retentionDays ?? 7)" />
                        <SubmitButton variant="secondary">{{ recovery.plan ? t('Set up again') : t('Turn on continuous backup') }}</SubmitButton>
                    </ApiForm>
                </template>
            </div>
        </SettingsSection>
        <SettingsSection
            v-if="recovery.plan && page.canRunCommands"
            id="restore"
            :title="t('Restore to a point in time')"
            :description="t('Stops the database, moves its current data aside (kept on the server), restores the last base backup before the moment and replays the log up to it. Applications see the database as it was then.')"
        >
            <ApiForm :action="`${base}/database-recovery/restore`" class="grid items-end gap-4 p-4 sm:grid-cols-3 sm:p-6">
                <InputField name="restore_to" type="datetime-local" step="1" :label="t('Restore to (UTC)')" required />
                <InputField name="confirmation" :label="t('Type :name to confirm', { name: page.server.name })" autocomplete="off" required />
                <SubmitButton variant="danger">{{ t('Restore') }}</SubmitButton>
            </ApiForm>
        </SettingsSection>
    </div>
</template>
