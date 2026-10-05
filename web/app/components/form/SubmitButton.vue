<script setup lang="ts">
/** The submit button, disabled while its form is sending (or while `disabled` says it can't be sent yet). */
withDefaults(defineProps<{ variant?: 'primary' | 'secondary' | 'danger' | 'soft' | 'quiet'; size?: 'sm' | 'lg'; disabled?: boolean }>(), { variant: 'primary', size: undefined, disabled: false });
const form = useForm();
const { t } = useT();
</script>

<template>
    <button type="submit" :disabled="form.busy || disabled" :aria-busy="form.busy || undefined" :class="['ui-btn', `ui-btn-${variant}`, size && `ui-btn-${size}`]">
        <template v-if="form.busy">{{ t('Working…') }}</template>
        <slot v-else />
    </button>
</template>
