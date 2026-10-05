<script setup lang="ts">
// Tiny trend line; `area` adds a soft gradient fill underneath.
const props = defineProps<{ values: number[]; label: string; area?: boolean }>()
const id = useId()
const pts = computed(() => {
  const min = Math.min(...props.values), max = Math.max(...props.values)
  return props.values.map((v, i) => [(i / (props.values.length - 1)) * 100, 27 - ((v - min) / (max - min || 1)) * 22] as const)
})
const line = computed(() => smoothPath(pts.value))
</script>

<template>
  <svg viewBox="0 0 100 30" preserveAspectRatio="none" role="img" :aria-label="label" class="overflow-visible">
    <defs><linearGradient :id="`${id}-g`" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="var(--color-series-1)" stop-opacity=".25" /><stop offset="1" stop-color="var(--color-series-1)" stop-opacity="0" /></linearGradient></defs>
    <path v-if="area" :d="`${line} L100,30 L0,30 Z`" :fill="`url(#${id}-g)`" />
    <path :d="line" fill="none" stroke="var(--color-series-1)" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round" />
  </svg>
</template>
