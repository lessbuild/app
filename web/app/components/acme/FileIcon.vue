<script setup lang="ts">
// Colour-coded icon for a file type.
const props = withDefaults(defineProps<{ kind: string; size?: 'sm' | 'md' | 'lg' }>(), { size: 'md' })
const map: Record<string, [string, string]> = {
  folder: ['folder', 'text-amber-500 bg-amber-500/10'],
  doc: ['fileText', 'text-blue-600 bg-blue-500/10'],
  sheet: ['sheet', 'text-emerald-600 bg-emerald-500/10'],
  pdf: ['page', 'text-rose-600 bg-rose-500/10'],
  image: ['image', 'text-violet-600 bg-violet-500/10'],
  slides: ['monitor', 'text-orange-600 bg-orange-500/10'],
}
const m = computed(() => map[props.kind] ?? ['page', 'text-muted bg-black/5'])
const sizes = { sm: ['size-7', 16], md: ['size-9', 18], lg: ['size-14', 28] } as const
</script>

<template>
  <span class="grid shrink-0 place-items-center rounded-lg" :class="[m[1], sizes[size][0]]" aria-hidden="true"><AcmeIcon :name="m[0]" :size="sizes[size][1]" /></span>
</template>
