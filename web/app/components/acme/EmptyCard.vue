<script setup lang="ts">
// An EmptyState inside a card. The default slot holds the call to action.
defineProps<{ icon?: string; title: string; description?: string }>()
</script>

<template>
  <AcmeCard :padded="false">
    <AcmeEmptyState :icon :title :description><slot v-if="$slots.default" /></AcmeEmptyState>
  </AcmeCard>
</template>
