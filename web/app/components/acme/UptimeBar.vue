<script setup lang="ts">
// Uptime strip, 90 days by default. Each bar: 'up' | 'degraded' | 'down'. Hover shows the bar's time.
// `step` is minutes per bar (default a day, e.g. 30 for a 24-hour strip of 48 bars); `compact` hides the legend.
const props = withDefaults(defineProps<{ days: ('up' | 'degraded' | 'down' | 'none')[]; label: string; step?: number; compact?: boolean }>(), { step: 1440 })
const colors = { up: 'bg-emerald-500', degraded: 'bg-amber-400', down: 'bg-rose-500', none: 'bg-black/10 dark:bg-white/10' }
const hover = ref<number | null>(null)
const measured = computed(() => props.days.filter(d => d !== 'none').length)
const pct = computed(() => (measured.value === 0 ? '—' : ((props.days.filter(d => d === 'up').length + props.days.filter(d => d === 'degraded').length * 0.5) / measured.value * 100).toFixed(2)))
const daily = computed(() => props.step >= 1440)
const { t, locale } = useT()
// The last bar is now (or today); the others step back from it.
const dayLabel = (i: number) => {
  const d = new Date(Date.now() - (props.days.length - 1 - i) * props.step * 6e4)
  return daily.value ? d.toLocaleDateString(locale.value, { month: 'short', day: 'numeric' }) : d.toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' })
}
const states = computed(() => ({ up: t('No incidents'), degraded: t('Degraded'), down: t('Outage'), none: t('No data') }))
const span = computed(() => {
  const mins = props.days.length * props.step
  return mins >= 2880 ? t(':count days', { count: Math.round(mins / 1440) }) : t(':count hours', { count: Math.round(mins / 60) })
})
</script>

<template>
  <div>
    <div class="relative flex h-8 gap-px" role="img" :aria-label="t(':label: :pct% uptime over :span', { label, pct, span })" @mouseleave="hover = null">
      <span v-for="(d, i) in days" :key="i" class="flex-1 rounded-[2px] transition-opacity" :class="[colors[d], hover !== null && hover !== i && 'opacity-50']" @mouseenter="hover = i" />
      <span v-if="hover !== null" class="pointer-events-none absolute -top-9 z-10 -translate-x-1/2 whitespace-nowrap rounded-md bg-zinc-900 px-2 py-1 text-xs text-white shadow" :style="{ left: `${((hover + 0.5) / days.length) * 100}%` }">{{ dayLabel(hover) }} · {{ states[days[hover]!] }}</span>
    </div>
    <div v-if="!compact" class="mt-1.5 flex justify-between text-xs text-muted"><span>{{ t(':span ago', { span }) }}</span><span>{{ t(':pct% uptime', { pct }) }}</span><span>{{ daily ? t('Today') : t('Now') }}</span></div>
  </div>
</template>
