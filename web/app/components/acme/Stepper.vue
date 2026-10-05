<script setup lang="ts">
// Horizontal progress through ordered steps.
defineProps<{ steps: readonly string[]; current: number; label: string }>()
</script>

<template>
  <ol class="flex overflow-x-auto rounded-2xl border border-line bg-surface shadow-card text-sm" :aria-label="label">
    <li v-for="(s, i) in steps" :key="s" class="flex flex-1 items-center gap-2 whitespace-nowrap px-4 py-3" :class="[i <= current ? 'text-ink' : 'text-muted', i > 0 && 'border-l border-line']" :aria-current="i === current ? 'step' : undefined">
      <span class="grid size-5 shrink-0 place-items-center rounded-full" :class="i < current ? 'bg-accent text-accent-fg' : i === current ? 'ring-2 ring-accent' : 'ring-1 ring-line'">
        <AcmeIcon v-if="i < current" name="check" :size="12" />
      </span>
      {{ s }}<span v-if="i < current" class="sr-only"> (done)</span>
    </li>
  </ol>
</template>
