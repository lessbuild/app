<script setup lang="ts">
const on = defineModel<boolean>({ default: false })
defineProps<{ label: string; showLabel?: boolean }>()
</script>

<template>
  <label class="inline-flex cursor-pointer items-center gap-3">
    <button type="button" role="switch" :aria-checked="on" :aria-label="showLabel ? undefined : label" class="relative h-6 w-11 shrink-0 rounded-full transition-colors" :class="on ? 'bg-accent' : 'bg-zinc-300 dark:bg-white/20'" @click="on = !on">
      <span class="absolute left-0.5 top-0.5 size-5 rounded-full bg-white shadow transition-transform" :class="on && 'translate-x-5'" />
    </button>
    <span v-if="showLabel" class="text-sm" @click="on = !on">{{ label }}</span>
  </label>
</template>
