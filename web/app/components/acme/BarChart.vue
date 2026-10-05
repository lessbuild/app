<script setup lang="ts">
// Single-series column chart: ≤24px bars, 4px rounded data-ends, hairline grid, hover tooltip, sr-only table.
// `highlight` ('max' or an index) spotlights one bar with a value pill and hatches the rest.
const props = withDefaults(defineProps<{ data: { label: string; value: number }[]; label: string; format?: (v: number) => string; height?: number; highlight?: 'max' | number }>(), { height: 200 })
const fmt = (v: number) => (props.format ? props.format(v) : String(v))
const niceMax = computed(() => {
  // A highlighted bar carries a value pill above it, so leave room for it under the top gridline.
  const max = Math.max(...props.data.map(d => d.value), 1) * (props.highlight === undefined ? 1 : 1.2)
  const step = 10 ** Math.floor(Math.log10(max))
  return Math.ceil(max / step) * step
})
const ticks = computed(() => [1, 0.5, 0].map(f => niceMax.value * f))
const hover = ref<number | null>(null)
const spot = computed(() => {
  if (props.highlight === undefined) return null
  if (props.highlight !== 'max') return props.highlight
  const max = Math.max(...props.data.map(d => d.value))
  return props.data.findIndex(d => d.value === max)
})
const focus = computed(() => hover.value ?? spot.value)
function barClass(i: number) {
  if (spot.value === null) return ['bg-series-1', hover.value !== null && hover.value !== i && 'opacity-40']
  return focus.value === i ? 'bg-series-1' : 'hatch bg-series-1/15 text-series-1'
}
</script>

<template>
  <figure>
    <div class="relative flex gap-3" :style="{ height: `${height}px` }" role="img" :aria-label="`${label}. ${data.map(d => `${d.label}: ${fmt(d.value)}`).join(', ')}`">
      <div class="flex flex-col justify-between pb-6 text-right text-xs tabular-nums text-muted" aria-hidden="true">
        <span v-for="t in ticks" :key="t" class="-translate-y-1/2 leading-none first:translate-y-0 last:translate-y-0">{{ fmt(t) }}</span>
      </div>
      <div class="relative flex-1">
        <div class="absolute inset-x-0 top-0 bottom-6 flex flex-col justify-between" aria-hidden="true">
          <div v-for="t in ticks" :key="t" class="h-px bg-line" />
        </div>
        <div class="absolute inset-0 flex">
          <div v-for="(d, i) in data" :key="d.label" class="relative flex flex-1 flex-col items-center" @mouseenter="hover = i" @mouseleave="hover = null">
            <div class="relative flex w-full flex-1 items-end justify-center">
              <div class="absolute inset-y-0 w-full max-w-6 rounded-t-md bg-black/[.03] dark:bg-white/[.04]" aria-hidden="true" />
              <div class="relative w-full max-w-6 rounded-t-[4px] transition" :class="barClass(i)" :style="{ height: `${(d.value / niceMax) * 100}%` }" />
              <div v-if="hover === i" class="absolute z-10 -translate-y-2 whitespace-nowrap rounded-lg border border-line bg-surface px-2.5 py-1.5 text-xs shadow-lg" :style="{ bottom: `${(d.value / niceMax) * 100}%` }">
                <span class="text-muted">{{ d.label }}</span> <b class="font-semibold tabular-nums">{{ fmt(d.value) }}</b>
              </div>
              <span v-if="spot === i && hover === null" class="absolute z-10 -translate-y-2 whitespace-nowrap rounded-full bg-ink px-2 py-0.5 text-[0.6875rem] font-semibold tabular-nums text-canvas shadow-lg" :style="{ bottom: `${(d.value / niceMax) * 100}%` }" aria-hidden="true">{{ fmt(d.value) }}</span>
            </div>
            <span class="h-6 w-full truncate px-0.5 pt-1.5 text-center text-[0.625rem] text-muted sm:text-xs" :title="d.label">{{ d.label }}</span>
          </div>
        </div>
      </div>
    </div>
    <table class="sr-only"><caption>{{ label }}</caption><tbody><tr v-for="d in data" :key="d.label"><th scope="row">{{ d.label }}</th><td>{{ fmt(d.value) }}</td></tr></tbody></table>
  </figure>
</template>
