<script setup lang="ts">
// Underlined in-page tabs. Pair each panel with role="tabpanel" if you need full ARIA wiring.
const value = defineModel<string>({ required: true })
const props = defineProps<{ tabs: (string | { label: string; value: string; count?: number })[]; label: string }>()
const items = computed(() => props.tabs.map(t => (typeof t === 'string' ? { label: t, value: t, count: undefined } : t)))
const root = ref<HTMLElement>()
function move(dir: number) {
  const i = items.value.findIndex(t => t.value === value.value)
  value.value = items.value[(i + dir + items.value.length) % items.value.length]!.value
  nextTick(() => root.value?.querySelector<HTMLElement>('[aria-selected="true"]')?.focus())
}
</script>

<template>
  <div ref="root" role="tablist" :aria-label="label" class="flex gap-6 overflow-x-auto border-b border-line" @keydown.right.prevent="move(1)" @keydown.left.prevent="move(-1)">
    <button
      v-for="t in items" :key="t.value" type="button" role="tab" :aria-selected="value === t.value" :tabindex="value === t.value ? 0 : -1"
      class="-mb-px flex items-center gap-2 whitespace-nowrap border-b-2 pb-3 text-sm font-medium transition-colors"
      :class="value === t.value ? 'border-accent text-ink' : 'border-transparent text-muted hover:text-ink'" @click="value = t.value"
    >
      {{ t.label }}
      <span v-if="t.count !== undefined" class="rounded-full bg-black/[.06] px-1.5 text-xs tabular-nums dark:bg-white/10">{{ t.count }}</span>
    </button>
  </div>
</template>
