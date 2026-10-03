<script setup lang="ts">
/** Signal's progress bar or meter. */
const props = withDefaults(defineProps<{ value: number; max?: number; label: string; role?: 'progressbar' | 'meter'; barClass?: string }>(), { max: 100, role: 'progressbar', barClass: undefined });
const maximum = computed(() => Math.max(1, props.max));
const current = computed(() => Math.min(maximum.value, Math.max(0, props.value)));
</script>

<template>
    <div :role="role" :aria-label="label" aria-valuemin="0" :aria-valuemax="maximum" :aria-valuenow="current" class="ui-progress">
        <span :class="barClass" :style="{ width: `${Math.round((current / maximum) * 10000) / 100}%` }" />
    </div>
</template>
