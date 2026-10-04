<script setup lang="ts">
import type { EnvironmentPage } from '~/types/deploy';

/** An environment's long-running processes (queue workers, the scheduler) that each deploy restarts. */
defineProps<{ page: EnvironmentPage; base: string }>();
const { t } = useT();
const types = computed(() => [{ value: 'worker', label: t('Worker') }, { value: 'scheduler', label: t('Scheduler') }]);
const restarts = computed(() => [{ value: 'always', label: t('Always') }, { value: 'on-failure', label: t('On failure') }]);
</script>

<template>
    <SettingsSection id="processes" :title="t('Workers and scheduler')" :description="t('Long-running processes each deploy restarts as systemd units, like queue workers or the scheduler.')">
        <div class="grid gap-4 p-4 sm:p-6">
            <div v-for="process in page.processes" :key="process.id" class="flex flex-wrap items-center justify-between gap-3 text-sm">
                <span class="min-w-0">
                    <span class="font-bold">{{ process.name }}</span>
                    <span class="ml-2 font-mono text-xs text-muted">{{ process.command }}</span>
                    <span class="text-xs text-muted"> · {{ process.type === 'scheduler' ? t('Scheduler') : t('Worker') }} · ×{{ process.replicas }}<template v-if="!process.enabled"> · {{ t('off') }}</template></span>
                </span>
                <ApiForm v-if="page.canManage" :action="`${base}/processes/${process.id}`" method="DELETE" :confirm="t('Remove :name?', { name: process.name })">
                    <SubmitButton variant="quiet" size="sm">{{ t('Remove') }}</SubmitButton>
                </ApiForm>
            </div>
            <p v-if="page.processes.length === 0" class="text-sm text-muted">{{ t('No workers yet.') }}</p>
            <FormDialog
                v-if="page.canManage"
                id="add-worker"
                :title="t('Add a worker')"
                :description="t('A queue worker or scheduler that runs beside the app and restarts if it stops.')"
                :action="`${base}/processes`"
                :submit="t('Save process')"
                size="wide"
            >
                <template #trigger="{ open }"><div><UiButton @click="open"><Icon name="plus" class="h-4 w-4" />{{ t('Add a worker') }}</UiButton></div></template>
                <div class="grid items-start gap-4 sm:grid-cols-3">
                    <InputField id="process-name" name="name" :label="t('Name')" placeholder="queue" maxlength="60" required autofocus />
                    <SelectField id="process-type" name="type" :label="t('Type')" :options="types" />
                    <InputField id="process-replicas" name="replicas" type="number" min="1" max="20" :label="t('Replicas')" model-value="1" required />
                    <div class="sm:col-span-3"><InputField id="process-command" name="command" :label="t('Command')" placeholder="php artisan queue:work --tries=3" maxlength="2000" required class="font-mono" /></div>
                    <SelectField id="process-restart" name="restart_policy" :label="t('Restart')" :options="restarts" />
                    <InputField id="process-delay" name="restart_delay_seconds" type="number" min="0" max="300" :label="t('Restart delay (s)')" model-value="5" required />
                </div>
            </FormDialog>
        </div>
    </SettingsSection>
</template>
