<script setup lang="ts">
defineProps<{ title: string; count?: number; danger?: boolean }>()
</script>

<template>
  <h2 class="section-label mb-3" :class="danger && 'text-rose-600 dark:text-rose-400'">
    {{ title }}<template v-if="count !== undefined"> · {{ count }}</template>
  </h2>
</template>
