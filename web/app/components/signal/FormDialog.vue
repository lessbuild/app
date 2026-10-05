<script setup lang="ts">
/** A dialog (drawer) holding one form, with Cancel and its submit button in a footer; it closes when the form is saved. */
withDefaults(defineProps<{
    id: string;
    title: string;
    description?: string;
    action: string;
    method?: 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    submit?: string;
    submitVariant?: 'primary' | 'danger' | 'secondary';
    size?: 'default' | 'wide' | 'large';
    /** Stay on this page after saving, whatever the API says. */
    stay?: boolean;
}>(), { description: undefined, method: 'POST', submit: undefined, submitVariant: 'primary', size: 'default', stay: false });
const { t } = useT();
</script>

<template>
    <UiDialog :id="id" :title="title" :description="description" :size="size" body-class="p-0">
        <template #trigger="{ open }"><slot name="trigger" :open="open" /></template>
        <template #default="{ close }">
            <ApiForm :action="action" :method="method" :stay="stay" class="!flex min-h-full flex-col !gap-0" @success="close">
                <div class="grid flex-1 content-start gap-5 px-5 py-5 sm:px-6"><slot /></div>
                <div class="sticky bottom-0 flex justify-end gap-2 border-t border-line bg-[var(--acme-panel)] px-5 py-3 sm:px-6">
                    <button type="button" class="ui-btn ui-btn-secondary" @click="close">{{ t('Cancel') }}</button>
                    <SubmitButton :variant="submitVariant">{{ submit ?? title }}</SubmitButton>
                </div>
            </ApiForm>
        </template>
    </UiDialog>
</template>
