<script setup lang="ts">
/** A labelled multi-line input. */
defineOptions({ inheritAttrs: false });
const props = withDefaults(defineProps<{ name: string; label: string; description?: string; hideLabel?: boolean; errorKey?: string; id?: string; required?: boolean }>(), {
    description: undefined, errorKey: undefined, id: undefined,
});
const model = defineModel<string | null>();
const controlId = computed(() => props.id ?? props.name);
const { field, error, describedBy } = useFieldState(() => controlId.value, () => props.name, () => !!props.description, () => props.errorKey);
</script>

<template>
    <FieldShell :id="controlId" :label="label" :description="description" :required="required" :hide-label="hideLabel" :field="field">
        <textarea v-bind="$attrs" :id="controlId" v-model="model" :name="name" :required="required" class="ui-input min-h-28" :aria-invalid="!!error" :aria-describedby="describedBy" />
    </FieldShell>
</template>
