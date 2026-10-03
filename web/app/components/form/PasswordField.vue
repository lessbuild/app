<script setup lang="ts">
/** A password input with a button to show what's typed, for checking a long password before submitting. */
defineOptions({ inheritAttrs: false });
const props = withDefaults(defineProps<{ name: string; label: string; description?: string; id?: string; required?: boolean }>(), { description: undefined, id: undefined });
const { t } = useT();
const visible = ref(false);
const controlId = computed(() => props.id ?? props.name);
</script>

<template>
    <InputField v-bind="$attrs" :id="controlId" :name="name" :label="label" :description="description" :required="required" :type="visible ? 'text' : 'password'">
        <template #suffix>
            <button type="button" class="ui-btn ui-btn-secondary rounded-l-none border-l-0 px-3 text-xs" :aria-controls="controlId" :aria-pressed="visible" @click="visible = !visible">
                {{ visible ? t('Hide') : t('Show') }}
            </button>
        </template>
    </InputField>
</template>
