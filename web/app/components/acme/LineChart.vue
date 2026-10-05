<script setup lang="ts">
// Multi-series line chart (≤3 series): 2px lines, hairline grid, crosshair + tooltip, legend + end labels.
const props = withDefaults(defineProps<{ labels: string[]; series: { name: string; values: number[] }[]; label: string; format?: (v: number) => string; height?: number }>(), { height: 220 })
const colors = ['var(--color-series-1)', 'var(--color-series-2)', 'var(--color-series-3)']
const fmt = (v: number) => (props.format ? props.format(v) : String(v))
const el = ref<HTMLElement>()
const width = ref(600)
const pad = { l: 40, r: 12, t: 12, b: 26 }
let ro: ResizeObserver | undefined
onMounted(() => {
  ro = new ResizeObserver(([e]) => (width.value = e!.contentRect.width))
  ro.observe(el.value!)
})
onBeforeUnmount(() => ro?.disconnect())

const max = computed(() => {
  const m = Math.max(...props.series.flatMap(s => s.values), 1)
  const step = 10 ** Math.floor(Math.log10(m))
  return Math.ceil(m / step) * step
})
// Show at most one x label per ~64px so long series (e.g. 30 days) don't overlap.
const every = computed(() => Math.max(1, Math.ceil(props.labels.length / Math.max(2, Math.floor((width.value - pad.l - pad.r) / 64)))))
const x = (i: number) => pad.l + (i / Math.max(1, props.labels.length - 1)) * (width.value - pad.l - pad.r)
const y = (v: number) => pad.t + (1 - v / max.value) * (props.height - pad.t - pad.b)
const paths = computed(() => props.series.map(s => smoothPath(s.values.map((v, i) => [x(i), y(v)] as const))))
const gid = useId()
const hover = ref<number | null>(null)
function onMove(e: PointerEvent) {
  const r = el.value!.getBoundingClientRect()
  const step = (width.value - pad.l - pad.r) / Math.max(1, props.labels.length - 1)
  hover.value = Math.min(props.labels.length - 1, Math.max(0, Math.round((e.clientX - r.left - pad.l) / step)))
}
const { t } = useT()
</script>

<template>
  <figure>
    <figcaption v-if="series.length > 1" class="mb-3 flex flex-wrap gap-4 text-xs text-muted">
      <span v-for="(s, i) in series" :key="s.name" class="flex items-center gap-1.5"><span class="h-0.5 w-3 rounded" :style="{ background: colors[i] }" />{{ s.name }}</span>
    </figcaption>
    <div ref="el" class="relative w-full overflow-hidden" :style="{ height: `${height}px` }" @pointermove="onMove" @pointerleave="hover = null">
      <svg :width :height role="img" :aria-label="label" class="absolute left-0 top-0 overflow-visible">
        <g class="text-[11px]" fill="var(--color-muted)">
          <template v-for="f in [0, 0.5, 1]" :key="f">
            <line :x1="pad.l" :x2="width - pad.r" :y1="y(max * f)" :y2="y(max * f)" stroke="var(--color-line)" stroke-width="1" />
            <text :x="pad.l - 8" :y="y(max * f) + 4" text-anchor="end" class="tabular-nums">{{ fmt(max * f) }}</text>
          </template>
          <template v-for="(l, i) in labels" :key="l"><text v-if="(i % every === 0 && labels.length - 1 - i >= every) || i === labels.length - 1" :x="x(i)" :y="height - 6" :text-anchor="i === 0 ? 'start' : i === labels.length - 1 ? 'end' : 'middle'">{{ l }}</text></template>
        </g>
        <line v-if="hover !== null" :x1="x(hover)" :x2="x(hover)" :y1="pad.t" :y2="height - pad.b" stroke="var(--color-muted)" stroke-opacity=".35" stroke-dasharray="3 3" />
        <defs>
          <linearGradient v-for="(c, i) in colors" :id="`${gid}-${i}`" :key="i" x1="0" x2="0" y1="0" y2="1"><stop offset="0" :stop-color="c" :stop-opacity="series.length > 1 ? 0.1 : 0.22" /><stop offset="1" :stop-color="c" stop-opacity="0" /></linearGradient>
        </defs>
        <path v-for="(d, i) in paths" :key="`a${i}`" :d="`${d} L${x(series[i]!.values.length - 1)},${height - pad.b} L${x(0)},${height - pad.b} Z`" :fill="`url(#${gid}-${i})`" />
        <path v-for="(d, i) in paths" :key="i" :d fill="none" :stroke="colors[i]" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
        <template v-for="(s, i) in series" :key="s.name">
          <circle v-if="hover !== null" :cx="x(hover)" :cy="y(s.values[hover]!)" r="4" :fill="colors[i]" stroke="var(--color-surface)" stroke-width="2" />
          <circle v-else :cx="x(s.values.length - 1)" :cy="y(s.values.at(-1)!)" r="4" :fill="colors[i]" stroke="var(--color-surface)" stroke-width="2" />
        </template>
      </svg>
      <div v-if="hover !== null" class="pointer-events-none absolute top-0 z-10 rounded-lg border border-line bg-surface px-3 py-2 text-xs shadow-lg" :style="{ left: `${Math.min(x(hover) + 12, width - 150)}px` }">
        <p class="mb-1 font-medium">{{ labels[hover] }}</p>
        <p v-for="(s, i) in series" :key="s.name" class="flex items-center gap-2"><span class="size-2 rounded-full" :style="{ background: colors[i] }" /><span class="text-muted">{{ s.name }}</span><b class="ml-auto pl-3 font-semibold tabular-nums">{{ fmt(s.values[hover]!) }}</b></p>
      </div>
    </div>
    <table class="sr-only"><caption>{{ label }}</caption><thead><tr><th>{{ t('Period') }}</th><th v-for="s in series" :key="s.name">{{ s.name }}</th></tr></thead><tbody><tr v-for="(l, i) in labels" :key="l"><th scope="row">{{ l }}</th><td v-for="s in series" :key="s.name">{{ fmt(s.values[i]!) }}</td></tr></tbody></table>
  </figure>
</template>
