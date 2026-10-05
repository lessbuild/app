<script setup lang="ts">
// KPI tile: tinted icon chip, big value, change pill and optional area sparkline.
// `icon`/`tone` can be passed explicitly; otherwise they are inferred from the label.
// `tinted` swaps the white card for a pastel wash in the tone's colour.
const props = defineProps<{ label: string; value: string; delta?: string; down?: boolean; spark?: number[]; icon?: string; tone?: Tone | 'sky'; tinted?: boolean }>()
const guesses: [RegExp, string, Tone | 'sky'][] = [
  [/overdue|low stock|expiring|bounce/i, 'alert', 'red'],
  [/win|award/i, 'award', 'violet'],
  [/revenue|sales|value|backlog|net worth|paid|outstanding|income/i, 'wallet', 'green'],
  [/spend|cost|price/i, 'receipt', 'amber'],
  [/visitor|people|user|contact|member/i, 'people', 'sky'],
  [/order|cart/i, 'cart', 'blue'],
  [/time|days|duration/i, 'clock', 'amber'],
  [/rate|conversion|savings/i, 'target', 'blue'],
  [/proposal|submitted|report/i, 'proposal', 'violet'],
  [/capture|opportunit|tracked|pipeline/i, 'capture', 'blue'],
  [/contract/i, 'contract', 'sky'],
  [/pageview|current/i, 'eye', 'sky'],
]
const guess = computed(() => guesses.find(([re]) => re.test(props.label)))
const iconName = computed(() => props.icon ?? guess.value?.[1] ?? 'trending')
const toneName = computed(() => props.tone ?? guess.value?.[2] ?? 'gray')
const chips: Record<string, string> = {
  green: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400', blue: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
  violet: 'bg-violet-500/10 text-violet-600 dark:text-violet-400', amber: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
  red: 'bg-rose-500/10 text-rose-600 dark:text-rose-400', sky: 'bg-sky-500/10 text-sky-600 dark:text-sky-400', gray: 'bg-black/[.05] text-ink/70 dark:bg-white/10',
}
const washes: Record<string, string> = {
  green: 'bg-emerald-50 border-emerald-100 dark:bg-emerald-500/10 dark:border-emerald-500/15', blue: 'bg-blue-50 border-blue-100 dark:bg-blue-500/10 dark:border-blue-500/15',
  violet: 'bg-violet-50 border-violet-100 dark:bg-violet-500/10 dark:border-violet-500/15', amber: 'bg-orange-50 border-orange-100 dark:bg-orange-500/10 dark:border-orange-500/15',
  red: 'bg-rose-50 border-rose-100 dark:bg-rose-500/10 dark:border-rose-500/15', sky: 'bg-sky-50 border-sky-100 dark:bg-sky-500/10 dark:border-sky-500/15', gray: 'bg-black/[.03] border-line dark:bg-white/[.04]',
}
</script>

<template>
  <div class="rounded-2xl border p-4 sm:p-5" :class="tinted ? washes[toneName] : 'border-line bg-surface shadow-card'">
    <div class="flex items-center gap-3">
      <span class="grid size-9 shrink-0 place-items-center rounded-xl" :class="tinted ? 'bg-surface/80 text-ink/70 shadow-card dark:bg-white/10' : chips[toneName]" aria-hidden="true"><AcmeIcon :name="iconName" :size="18" /></span>
      <p class="line-clamp-2 min-w-0 text-sm font-medium leading-snug text-muted">{{ label }}</p>
    </div>
    <div class="mt-4 flex items-end justify-between gap-3">
      <p class="text-xl font-semibold tabular-nums tracking-tight sm:text-[1.75rem] sm:leading-none">{{ value }}</p>
      <AcmeSparkline v-if="spark" :values="spark" :label="`${label} trend`" area class="hidden h-10 w-24 sm:block" />
    </div>
    <p v-if="delta" class="mt-3 flex flex-wrap items-center gap-1.5 text-xs">
      <span class="inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 font-semibold" :class="down ? 'bg-rose-500/10 text-rose-700 dark:text-rose-300' : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'">
        <AcmeIcon :name="down ? 'sortDown' : 'sortUp'" :size="14" />{{ delta }}
      </span>
      <span class="hidden text-muted sm:inline">vs last month</span>
    </p>
  </div>
</template>
