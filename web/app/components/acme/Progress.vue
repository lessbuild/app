<script setup lang="ts">
// `striped` draws the unfilled part as a hatched track, for goals and limits.
withDefaults(defineProps<{ value: number; label: string; color?: string; size?: 'sm' | 'md' | 'lg'; striped?: boolean }>(), { color: 'bg-accent', size: 'md' })
const heights = { sm: 'h-1.5', md: 'h-2', lg: 'h-3' }
</script>

<template>
  <div role="progressbar" :aria-label="label" :aria-valuenow="value" aria-valuemin="0" aria-valuemax="100" class="overflow-hidden rounded-full" :class="[heights[size], striped ? 'hatch bg-black/[.03] text-ink dark:bg-white/[.04]' : 'bg-black/[.07] dark:bg-white/10']">
    <div class="h-full rounded-full transition-[width] duration-500" :class="color" :style="{ width: `${Math.min(100, Math.max(0, value))}%` }" />
  </div>
</template>
