<script setup lang="ts">
// 90-day uptime strip. Each day: 'up' | 'degraded' | 'down'. Hover/focus shows the day.
const props = defineProps<{ days: ('up' | 'degraded' | 'down')[]; label: string }>()
const colors = { up: 'bg-emerald-500', degraded: 'bg-amber-400', down: 'bg-rose-500' }
const hover = ref<number | null>(null)
const pct = computed(() => ((props.days.filter(d => d === 'up').length + props.days.filter(d => d === 'degraded').length * 0.5) / props.days.length * 100).toFixed(2))
const { t, locale } = useT()
const dayLabel = (i: number) => new Date(Date.now() - (props.days.length - 1 - i) * 864e5).toLocaleDateString(locale.value, { month: 'short', day: 'numeric' })
const states = computed(() => ({ up: t('No incidents'), degraded: t('Degraded'), down: t('Outage') }))
</script>

<template>
  <div>
    <div class="relative flex h-8 gap-px" role="img" :aria-label="t(':label: :pct% uptime over :days days', { label, pct, days: days.length })" @mouseleave="hover = null">
      <span v-for="(d, i) in days" :key="i" class="flex-1 rounded-[2px] transition-opacity" :class="[colors[d], hover !== null && hover !== i && 'opacity-50']" @mouseenter="hover = i" />
      <span v-if="hover !== null" class="pointer-events-none absolute -top-9 z-10 -translate-x-1/2 whitespace-nowrap rounded-md bg-zinc-900 px-2 py-1 text-xs text-white shadow" :style="{ left: `${((hover + 0.5) / days.length) * 100}%` }">{{ dayLabel(hover) }} · {{ states[days[hover]!] }}</span>
    </div>
    <div class="mt-1.5 flex justify-between text-xs text-muted"><span>{{ t(':count days ago', { count: days.length }) }}</span><span>{{ t(':pct% uptime', { pct }) }}</span><span>{{ t('Today') }}</span></div>
  </div>
</template>
