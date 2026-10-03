<script setup lang="ts">
/**
 * A labelled text input that shows its validation error. Other attributes (type, autocomplete, required…) go on the
 * input; the `prefix` and `suffix` slots sit either side of it.
 */
defineOptions({ inheritAttrs: false });
const props = withDefaults(defineProps<{ name: string; label: string; description?: string; hideLabel?: boolean; errorKey?: string; id?: string; required?: boolean }>(), {
    description: undefined, errorKey: undefined, id: undefined,
});
const model = defineModel<string>();
const slots = useSlots();
const controlId = computed(() => props.id ?? props.name);
const { field, error, describedBy } = useFieldState(() => controlId.value, () => props.name, () => !!props.description, () => props.errorKey);
</script>

<template>
    <FieldShell :id="controlId" :label="label" :description="description" :required="required" :hide-label="hideLabel" :field="field">
        <div v-if="slots.prefix || slots.suffix" class="flex min-w-0">
            <slot name="prefix" />
            <input v-bind="$attrs" :id="controlId" v-model="model" :name="name" :required="required" :class="['ui-input min-w-0 w-full', slots.prefix && 'rounded-l-none', slots.suffix && 'rounded-r-none']" :aria-invalid="!!error" :aria-describedby="describedBy">
            <slot name="suffix" />
        </div>
        <input v-else v-bind="$attrs" :id="controlId" v-model="model" :name="name" :required="required" class="ui-input min-w-0 w-full" :aria-invalid="!!error" :aria-describedby="describedBy">
    </FieldShell>
</template>
