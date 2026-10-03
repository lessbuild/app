<script setup lang="ts">
/** Signal's choice: a radio or checkbox with a label and help, optionally as a card; the slot adds detail under it. */
defineOptions({ inheritAttrs: false });
const props = withDefaults(defineProps<{ id: string; name: string; label: string; description?: string; type?: 'checkbox' | 'radio'; card?: boolean; value?: string; errorKey?: string; required?: boolean }>(), {
    description: undefined, type: 'checkbox', value: '1', errorKey: undefined,
});
const { field, error, describedBy } = useFieldState(() => props.id, () => props.name, () => !!props.description, () => props.errorKey);
</script>

<template>
    <div class="min-w-0">
        <label :for="id" :class="card ? 'ui-choice' : 'inline-flex min-h-10 cursor-pointer items-start gap-3 py-1'">
            <input v-bind="$attrs" :id="id" :name="name" :type="type" :value="value" :required="required" :aria-invalid="!!error" :aria-describedby="describedBy" class="ui-check mt-0.5 shrink-0">
            <span class="min-w-0 flex-1 wrap-anywhere">
                <span class="block text-sm font-semibold text-ink">{{ label }}<span v-if="required" class="text-danger" aria-hidden="true">*</span></span>
                <span v-if="description" :id="`${id}-help`" class="ui-help block">{{ description }}</span>
                <slot />
            </span>
        </label>
        <FieldError :id="`${id}-error`" :name="field" />
    </div>
</template>
