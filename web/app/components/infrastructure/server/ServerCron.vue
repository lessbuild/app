<script setup lang="ts">
import type { ServerPage } from '~/types/infrastructure';

/** The commands a server runs on a schedule, written to /etc/cron.d. */
const props = defineProps<{ page: ServerPage; base: string }>();
const { t } = useT();
const users = computed(() => [{ value: props.page.server.name, label: props.page.server.name }, { value: 'root', label: 'root' }]);
</script>

<template>
    <SettingsSection
        :title="t('Cron jobs')"
        :description="t('Commands the server runs on a schedule. Laravel apps need one running php artisan schedule:run every minute. Output goes to a log in the user’s home folder.')"
    >
        <div class="grid gap-4 p-4 sm:p-6">
            <div v-for="job in page.cronJobs" :key="job.id" class="flex flex-wrap items-start justify-between gap-3 border-b border-line pb-3 last:border-0 last:pb-0">
                <div class="min-w-0">
                    <p class="break-all font-mono text-sm text-ink">{{ job.command }}</p>
                    <p class="text-xs text-muted">{{ job.schedule }} · {{ t('as :user', { user: job.user }) }} · <span class="font-mono">{{ job.frequency }}</span></p>
                    <TaskStatusBadge :status="job.status" :error="job.error" />
                </div>
                <span v-if="job.status !== 'removing'" class="flex shrink-0 items-center gap-2">
                    <FormDialog :id="`edit-cron-${job.id}`" :title="t('Edit cron job')" :action="`${base}/cron-jobs/${job.id}`" method="PUT" :submit="t('Save')" size="wide">
                        <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Edit') }}</UiButton></template>
                        <CronFields :job="job" :users="users" :presets="page.cronPresets" :prefix="`edit-cron-${job.id}`" :server-name="page.server.name" />
                    </FormDialog>
                    <ApiForm :action="`${base}/cron-jobs/${job.id}`" method="DELETE"><SubmitButton variant="quiet" size="sm">{{ t('Remove') }}</SubmitButton></ApiForm>
                </span>
            </div>
            <p v-if="page.cronJobs.length === 0" class="text-sm text-muted">{{ t('No cron jobs yet.') }}</p>
            <FormDialog id="add-cron" :title="t('Add a cron job')" :action="`${base}/cron-jobs`" :submit="t('Add cron job')" size="wide">
                <template #trigger="{ open }"><div><UiButton size="sm" @click="open"><Icon name="plus" class="h-4 w-4" />{{ t('Add a cron job') }}</UiButton></div></template>
                <CronFields :job="null" :users="users" :presets="page.cronPresets" prefix="add-cron" :server-name="page.server.name" />
            </FormDialog>
        </div>
    </SettingsSection>
</template>
