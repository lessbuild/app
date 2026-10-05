<script setup lang="ts">
// Part-to-whole donut, ≤4 slices in fixed palette order, 2px surface gaps, legend with values.
const props = withDefaults(defineProps<{ data: { label: string; value: number }[]; label: string; format?: (v: number) => string }>(), {})
const colors = ['var(--color-series-1)', 'var(--color-series-2)', 'var(--color-series-3)', 'var(--color-series-4)']
const fmt = (v: number) => (props.format ? props.format(v) : String(v))
const total = computed(() => props.data.reduce((s, d) => s + d.value, 0))
const R = 42, C = 2 * Math.PI * R
const slices = computed(() => {
  let acc = 0
  return props.data.map((d, i) => {
    const len = (d.value / total.value) * C
    const s = { ...d, color: colors[i], dash: `${Math.max(0, len - 2)} ${C}`, offset: -acc }
    acc += len
    return s
  })
})
const hover = ref<number | null>(null)
const { t } = useT()
</script>

<template>
  <figure class="flex flex-wrap items-center gap-6">
    <div class="relative size-40 shrink-0">
      <svg viewBox="0 0 100 100" class="-rotate-90" role="img" :aria-label="`${label}. ${data.map(d => `${d.label}: ${fmt(d.value)}`).join(', ')}`">
        <circle v-for="(s, i) in slices" :key="s.label" cx="50" cy="50" :r="R" fill="none" :stroke="s.color" :stroke-width="hover === i ? 14 : 11" :stroke-dasharray="s.dash" :stroke-dashoffset="s.offset" class="transition-[stroke-width]" @mouseenter="hover = i" @mouseleave="hover = null" />
      </svg>
      <div class="pointer-events-none absolute inset-0 grid place-items-center text-center">
        <div>
          <p class="text-xl font-semibold tabular-nums">{{ fmt(hover === null ? total : data[hover]!.value) }}</p>
          <p class="text-xs text-muted">{{ hover === null ? t('Total') : data[hover]!.label }}</p>
        </div>
      </div>
    </div>
    <ul class="min-w-40 flex-1 space-y-2 text-sm">
      <li v-for="(s, i) in slices" :key="s.label" class="flex items-center gap-2" @mouseenter="hover = i" @mouseleave="hover = null">
        <span class="size-2.5 rounded-sm" :style="{ background: s.color }" />
        <span class="text-muted">{{ s.label }}</span>
        <span class="ml-auto font-medium tabular-nums">{{ fmt(s.value) }}</span>
        <span class="w-10 text-right text-xs tabular-nums text-muted">{{ Math.round((s.value / total) * 100) }}%</span>
      </li>
    </ul>
  </figure>
</template>
