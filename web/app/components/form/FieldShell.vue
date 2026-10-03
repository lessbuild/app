<script setup lang="ts">
/** Signal's field: the label, the control (the slot), its help and its error. */
defineProps<{ id: string; label: string; description?: string; required?: boolean; hideLabel?: boolean; field: string }>();
</script>

<template>
    <div class="grid min-w-0 gap-2">
        <label :for="id" :class="['ui-label', hideLabel ? 'sr-only' : 'wrap-anywhere']">
            <slot name="label">{{ label }}</slot>
            <span v-if="required" class="text-danger" aria-hidden="true">*</span>
        </label>
        <slot />
        <p v-if="description" :id="`${id}-help`" class="ui-help wrap-anywhere">{{ description }}</p>
        <FieldError :id="`${id}-error`" :name="field" />
    </div>
</template>
