<script setup lang="ts">
/** Signal's status dot, optionally in a colour of its own. */
withDefaults(defineProps<{ size?: 'sm' | 'lg'; color?: string }>(), { size: 'sm', color: undefined });
</script>

<template>
    <span :class="['ui-status-dot', size === 'lg' && 'ui-status-dot-lg']" :style="color ? { '--ui-status-dot': color } : undefined" />
</template>
