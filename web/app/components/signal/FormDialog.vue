<script setup lang="ts">
/** A dialog holding one form, its submit button at the bottom right; it closes when the form is saved. */
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
</script>

<template>
    <UiDialog :id="id" :title="title" :description="description" :size="size">
        <template #trigger="{ open }"><slot name="trigger" :open="open" /></template>
        <template #default="{ close }">
            <ApiForm :action="action" :method="method" :stay="stay" @success="close">
                <slot />
                <div class="flex justify-end gap-2">
                    <SubmitButton :variant="submitVariant">{{ submit ?? title }}</SubmitButton>
                </div>
            </ApiForm>
        </template>
    </UiDialog>
</template>
