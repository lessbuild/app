<script setup lang="ts">
// Half-circle gauge: rounded arc on a hatched track, big value in the middle, caption underneath.
const props = withDefaults(defineProps<{ value: number; label: string; caption?: string; display?: string; color?: string }>(), { color: 'var(--color-series-3)' })
const pct = computed(() => Math.min(100, Math.max(0, props.value)))
const arc = 'M10,60 A50,50 0 0 1 110,60'
</script>

<template>
  <figure class="relative mx-auto w-full max-w-60" role="meter" :aria-label="label" :aria-valuenow="pct" aria-valuemin="0" aria-valuemax="100">
    <svg viewBox="0 0 120 66" class="w-full overflow-visible" aria-hidden="true">
      <path :d="arc" fill="none" stroke="currentColor" class="text-black/[.06] dark:text-white/10" stroke-width="14" stroke-linecap="round" />
      <path :d="arc" fill="none" :stroke="color" stroke-width="14" stroke-linecap="round" pathLength="100" :stroke-dasharray="`${pct} 100`" class="transition-[stroke-dasharray] duration-700" />
    </svg>
    <figcaption class="absolute inset-x-0 bottom-0 text-center">
      <span class="block text-2xl font-semibold tabular-nums tracking-tight">{{ display ?? `${Math.round(pct)}%` }}</span>
      <span v-if="caption" class="block text-xs text-muted">{{ caption }}</span>
    </figcaption>
  </figure>
</template>
