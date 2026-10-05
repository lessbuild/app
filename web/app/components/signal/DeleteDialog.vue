<script setup lang="ts">
/** Ask before deleting something, as Signal's delete confirmation does; the slot adds fields (such as typing the name). */
withDefaults(defineProps<{ id: string; title: string; description?: string; action: string; warning?: string; submitLabel?: string }>(), {
    description: undefined, warning: undefined, submitLabel: undefined,
});
const { t } = useT();
</script>

<template>
    <UiDialog :id="id" :title="title" :description="description" body-class="p-0">
        <template #trigger="{ open }"><slot name="trigger" :open="open" /></template>
        <template #default="{ close }">
            <ApiForm :action="action" method="DELETE" class="!flex min-h-full flex-col !gap-0" @success="close">
                <div v-if="$slots.default" class="grid gap-4 px-5 pt-5 sm:px-6"><slot /></div>
                <div class="flex flex-1 items-start gap-4 px-5 py-5 sm:px-6">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-line bg-surface-muted text-[var(--ui-danger)]" aria-hidden="true">
                        <Icon name="information-circle" class="h-5 w-5" />
                    </span>
                    <p class="text-sm text-muted">{{ warning ?? t('This action cannot be undone.') }}</p>
                </div>
                <div class="sticky bottom-0 flex flex-wrap justify-end gap-2 border-t border-line bg-[var(--acme-panel)] px-5 py-3 sm:px-6">
                    <button type="button" class="ui-btn ui-btn-secondary" @click="close">{{ t('Cancel') }}</button>
                    <SubmitButton variant="danger">{{ submitLabel ?? t('Delete') }}</SubmitButton>
                </div>
            </ApiForm>
        </template>
    </UiDialog>
</template>
