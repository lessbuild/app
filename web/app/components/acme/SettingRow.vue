<script setup lang="ts">
defineProps<{ title: string; description?: string }>()
</script>

<template>
  <div class="grid gap-4 py-7 md:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)] md:gap-10">
    <div>
      <h3 class="text-[1.125rem] font-semibold">{{ title }}</h3>
      <p v-if="description" class="mt-1 text-[1.0625rem] text-muted">{{ description }}</p>
    </div>
    <div class="min-w-0"><slot /></div>
  </div>
</template>
