<script setup lang="ts">
/** A checkbox with its label; `uncheckedValue` sends a value when it's left unticked. */
defineOptions({ inheritAttrs: false });
const props = withDefaults(defineProps<{ name: string; label: string; description?: string; errorKey?: string; id?: string; value?: string; uncheckedValue?: string }>(), {
    description: undefined, errorKey: undefined, id: undefined, value: '1', uncheckedValue: undefined,
});
const controlId = computed(() => props.id ?? props.name);
const { field, error, describedBy } = useFieldState(() => controlId.value, () => props.name, () => !!props.description, () => props.errorKey);
</script>

<template>
    <div class="grid gap-2">
        <input v-if="uncheckedValue !== undefined" type="hidden" :name="name" :value="uncheckedValue">
        <label class="inline-flex min-h-10 cursor-pointer items-center gap-2 text-sm font-semibold text-ink">
            <input v-bind="$attrs" :id="controlId" :name="name" type="checkbox" :value="value" :aria-invalid="!!error" :aria-describedby="describedBy" class="h-4 w-4 rounded border-line accent-[var(--ui-primary)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
            <span>{{ label }}</span>
        </label>
        <p v-if="description" :id="`${controlId}-help`" class="ui-help">{{ description }}</p>
        <FieldError :id="`${controlId}-error`" :name="field" />
    </div>
</template>
