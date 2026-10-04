<script setup lang="ts">
// Single-select pill filters.
const value = defineModel<string>({ required: true })
defineProps<{ options: string[]; label: string }>()
</script>

<template>
  <div class="flex flex-wrap gap-2" role="group" :aria-label="label">
    <button
      v-for="o in options" :key="o" type="button" :aria-pressed="value === o" class="rounded-full border px-3 py-1.5 text-sm transition-colors"
      :class="value === o ? 'border-accent bg-accent text-accent-fg' : 'border-line bg-surface text-muted hover:text-ink'" @click="value = o"
    >{{ o }}</button>
  </div>
</template>
