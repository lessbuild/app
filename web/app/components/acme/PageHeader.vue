<script setup lang="ts">
defineProps<{ title: string; subtitle?: string; crumbs?: { label: string; to?: string }[] }>()
</script>

<template>
  <header class="px-4 pb-6 pt-6 sm:px-6 sm:pt-8 lg:px-10">
    <AcmeBreadcrumbs v-if="crumbs" :items="crumbs" class="mb-3" />
    <div class="flex flex-wrap items-end justify-between gap-4">
      <div class="min-w-0">
        <h1 class="text-2xl font-semibold tracking-tight sm:text-[1.75rem]">{{ title }}</h1>
        <p v-if="subtitle" class="mt-1 text-muted">{{ subtitle }}</p>
      </div>
      <div v-if="$slots.default" class="flex flex-wrap gap-2"><slot /></div>
    </div>
  </header>
</template>
