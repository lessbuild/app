<script setup lang="ts">
const props = withDefaults(defineProps<{ tone?: 'gray' | 'green' | 'blue' | 'amber' | 'red' | 'violet'; dot?: boolean }>(), { tone: 'gray' })
const tones = {
  green: 'bg-emerald-500/10 text-emerald-700 ring-emerald-600/15 dark:text-emerald-300',
  blue: 'bg-blue-500/10 text-blue-700 ring-blue-600/15 dark:text-blue-300',
  amber: 'bg-amber-500/10 text-amber-800 ring-amber-600/20 dark:text-amber-300',
  red: 'bg-rose-500/10 text-rose-700 ring-rose-600/15 dark:text-rose-300',
  violet: 'bg-violet-500/10 text-violet-700 ring-violet-600/15 dark:text-violet-300',
  gray: 'bg-black/[.04] text-zinc-700 ring-black/[.06] dark:bg-white/10 dark:text-zinc-300 dark:ring-white/10',
}
</script>

<template>
  <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset" :class="tones[props.tone]">
    <span v-if="dot" class="size-1.5 rounded-full bg-current" aria-hidden="true" />
    <slot />
  </span>
</template>
