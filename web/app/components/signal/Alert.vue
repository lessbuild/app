<script setup lang="ts">
/** Signal's alert: a message about the page or an action. */
withDefaults(defineProps<{ tone?: 'info' | 'success' | 'warning' | 'danger'; role?: 'status' | 'alert' }>(), { tone: 'info', role: undefined });
</script>

<template>
    <div data-ui-feedback="alert" :role="role" :class="['ui-alert', `ui-alert--${tone}`, tone !== 'info' && `ui-alert-${tone}`]"><slot /></div>
</template>
