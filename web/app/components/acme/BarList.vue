<script setup lang="ts">
// Ranked horizontal bars with labels inside (analytics "top pages" style). Single hue; values in ink.
const props = withDefaults(defineProps<{ items: { label: string; value: number; to?: string }[]; label: string; format?: (v: number) => string; valueLabel?: string }>(), { valueLabel: undefined })
const max = computed(() => Math.max(...props.items.map(i => i.value), 1))
const { t, number } = useT()
const fmt = (v: number) => (props.format ? props.format(v) : number(v))
</script>

<template>
  <div>
    <div class="mb-2 flex justify-between text-xs font-medium uppercase tracking-wide text-muted"><span>{{ label }}</span><span>{{ valueLabel ?? t('Visitors') }}</span></div>
    <ul class="space-y-1.5" :aria-label="label">
      <li v-for="i in items" :key="i.label" class="flex items-center gap-3 text-sm">
        <div class="relative h-8 min-w-0 flex-1">
          <div class="absolute inset-y-0 left-0 rounded-md bg-series-1/15 dark:bg-series-1/25" :style="{ width: `${(i.value / max) * 100}%` }" />
          <span class="relative flex h-full items-center truncate px-2.5">{{ i.label }}</span>
        </div>
        <span class="w-16 shrink-0 text-right font-medium tabular-nums">{{ fmt(i.value) }}</span>
      </li>
    </ul>
  </div>
</template>
