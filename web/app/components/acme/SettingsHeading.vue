<script setup lang="ts">
defineProps<{ title: string; description?: string }>()
</script>

<template>
  <div class="border-b border-line pb-7">
    <h2 class="text-xl font-semibold tracking-tight">{{ title }}</h2>
    <p v-if="description" class="mt-1 text-muted">{{ description }}</p>
  </div>
</template>
