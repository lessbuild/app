<script setup lang="ts">
// Single-choice segmented control (radio group semantics, arrow-key navigation).
const value = defineModel<string | number>({ required: true })
const props = defineProps<{ options: (string | { label: string; value: string | number })[]; label: string; size?: 'sm' | 'md' }>()
const opts = computed(() => props.options.map(o => (typeof o === 'string' ? { label: o, value: o } : o)))
const root = ref<HTMLElement>()
function move(e: KeyboardEvent, dir: number) {
  e.preventDefault()
  const i = opts.value.findIndex(o => o.value === value.value)
  const next = opts.value[(i + dir + opts.value.length) % opts.value.length]!
  value.value = next.value
  nextTick(() => root.value?.querySelector<HTMLElement>('[aria-checked="true"]')?.focus())
}
</script>

<template>
  <div ref="root" role="radiogroup" :aria-label="label" class="inline-flex rounded-lg border border-line bg-surface p-1" @keydown.right="move($event, 1)" @keydown.down="move($event, 1)" @keydown.left="move($event, -1)" @keydown.up="move($event, -1)">
    <button
      v-for="o in opts" :key="o.value" type="button" role="radio" :aria-checked="value === o.value" :tabindex="value === o.value ? 0 : -1"
      class="rounded-md font-medium transition-colors" :class="[size === 'sm' ? 'px-2.5 py-1 text-xs' : 'px-3.5 py-1.5 text-sm', value === o.value ? 'bg-accent text-accent-fg' : 'text-muted hover:text-ink']"
      @click="value = o.value"
    >{{ o.label }}</button>
  </div>
</template>
