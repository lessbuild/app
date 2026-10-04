<script setup lang="ts">
import type { ServerPage } from '~/types/infrastructure';

/** Long-running commands Supervisor keeps alive on a server, such as queue workers, Horizon or Reverb. */
const props = defineProps<{ page: ServerPage; base: string }>();
const { t, tc } = useT();
const users = computed(() => [{ value: props.page.server.name, label: props.page.server.name }, { value: 'root', label: 'root' }]);
const preset = ref<string | null>(null);
const presetFields = computed(() => {
    const chosen = preset.value ? props.page.processPresets[preset.value] : undefined;
    return chosen ? { name: chosen.name, command: chosen.command, copies: chosen.processes, stopWaitSeconds: chosen.stop_wait_seconds, user: props.page.server.name, directory: null } : null;
});
</script>

<template>
    <SettingsSection
        :title="t('Processes')"
        :description="t('Commands that should always be running, such as queue workers, Horizon or Reverb. Supervisor starts them, restarts them if they stop, and gives them time to finish their work when stopped.')"
    >
        <div class="grid gap-4 p-4 sm:p-6">
            <div v-for="process in page.processes" :key="process.id" class="flex flex-wrap items-start justify-between gap-3 border-b border-line pb-3 last:border-0 last:pb-0">
                <div class="min-w-0">
                    <p class="font-bold text-ink">{{ process.name }}</p>
                    <p class="break-all font-mono text-xs text-muted">{{ process.command }}</p>
                    <p class="text-xs text-muted">
                        {{ tc(':count copy|:count copies', process.copies, { count: process.copies }) }} · {{ t('as :user', { user: process.user }) }}<template v-if="process.directory"> · {{ process.directory }}</template>
                    </p>
                    <TaskStatusBadge :status="process.status" :error="process.error" />
                </div>
                <span class="flex shrink-0 items-center gap-2">
                    <ApiForm v-if="process.status === 'active'" :action="`${base}/processes/${process.id}/restart`"><SubmitButton variant="secondary" size="sm">{{ t('Restart') }}</SubmitButton></ApiForm>
                    <template v-if="process.status !== 'removing'">
                        <FormDialog :id="`edit-process-${process.id}`" :title="t('Edit process')" :action="`${base}/processes/${process.id}`" method="PUT" :submit="t('Save')" size="wide">
                            <template #trigger="{ open }"><UiButton variant="quiet" size="sm" @click="open">{{ t('Edit') }}</UiButton></template>
                            <ProcessFields :process="process" :users="users" :prefix="`edit-process-${process.id}`" :server-name="page.server.name" />
                        </FormDialog>
                        <ApiForm :action="`${base}/processes/${process.id}`" method="DELETE"><SubmitButton variant="quiet" size="sm">{{ t('Remove') }}</SubmitButton></ApiForm>
                    </template>
                </span>
            </div>
            <p v-if="page.processes.length === 0" class="text-sm text-muted">{{ t('No processes yet.') }}</p>
            <FormDialog id="add-process" :title="t('Add a process')" :action="`${base}/processes`" :submit="t('Add process')" size="wide">
                <template #trigger="{ open }"><div><UiButton size="sm" @click="open"><Icon name="plus" class="h-4 w-4" />{{ t('Add a process') }}</UiButton></div></template>
                <nav class="mb-4 flex flex-wrap gap-2" :aria-label="t('Start from')">
                    <UiButton v-for="(item, key) in page.processPresets" :key="key" size="sm" :variant="preset === key ? 'soft' : 'secondary'" @click="preset = String(key)">{{ item.name }}</UiButton>
                </nav>
                <ProcessFields :key="preset ?? 'blank'" :process="presetFields" :users="users" prefix="add-process" :server-name="page.server.name" />
            </FormDialog>
        </div>
    </SettingsSection>
</template>
