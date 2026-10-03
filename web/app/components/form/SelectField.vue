<script setup lang="ts">
import type { Option } from '~/types/ui';

/** A labelled select, from a list of options (and any <option>s in the slot). */
defineOptions({ inheritAttrs: false });
const props = withDefaults(defineProps<{ name: string; label: string; description?: string; hideLabel?: boolean; errorKey?: string; id?: string; required?: boolean; options?: Option[]; placeholder?: string }>(), {
    description: undefined, errorKey: undefined, id: undefined, options: () => [], placeholder: undefined,
});
const model = defineModel<string>();
// Without a value from the page, start on the placeholder or, without one, the first option (as a plain select does).
if (model.value === undefined) {
    model.value = props.placeholder !== undefined ? '' : props.options[0]?.value;
}
const controlId = computed(() => props.id ?? props.name);
const { field, error, describedBy } = useFieldState(() => controlId.value, () => props.name, () => !!props.description, () => props.errorKey);
</script>

<template>
    <FieldShell :id="controlId" :label="label" :description="description" :required="required" :hide-label="hideLabel" :field="field">
        <select v-bind="$attrs" :id="controlId" v-model="model" :name="name" :required="required" class="ui-input" :aria-invalid="!!error" :aria-describedby="describedBy">
            <option v-if="placeholder !== undefined" value="">{{ placeholder }}</option>
            <option v-for="option in options" :key="option.value" :value="option.value" :disabled="option.disabled">{{ option.label }}</option>
            <slot />
        </select>
    </FieldShell>
</template>
