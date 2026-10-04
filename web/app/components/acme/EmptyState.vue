<script setup lang="ts">
// Empty state with a soft layered illustration around the icon.
defineProps<{ icon?: string; title: string; description?: string }>()
</script>

<template>
  <div class="mx-auto flex max-w-sm flex-col items-center px-6 py-12 text-center">
    <div v-if="icon" class="relative mb-5 grid size-28 place-items-center" aria-hidden="true">
      <span class="absolute inset-0 rounded-full bg-accent/[.03] dark:bg-white/[.02]" />
      <span class="absolute inset-4 rounded-full bg-accent/[.05] dark:bg-white/[.04]" />
      <span class="absolute left-3 top-6 size-2 rounded-full bg-series-1/40" />
      <span class="absolute bottom-7 right-2 size-1.5 rounded-full bg-series-2/50" />
      <span class="absolute right-6 top-3 size-1 rounded-full bg-series-3/60" />
      <span class="relative grid size-14 rotate-[-6deg] place-items-center rounded-2xl border border-line bg-surface text-muted shadow-lift"><AcmeIcon :name="icon" :size="24" /></span>
    </div>
    <h3 class="font-semibold">{{ title }}</h3>
    <p v-if="description" class="mt-1 text-sm text-muted">{{ description }}</p>
    <div v-if="$slots.default" class="mt-5"><slot /></div>
  </div>
</template>
