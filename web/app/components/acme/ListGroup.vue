<script setup lang="ts">
// Divided list. `bordered` draws its own rounded box; leave it off inside a Card.
defineProps<{ bordered?: boolean; label?: string }>()
</script>

<template>
  <ul class="divide-y divide-line" :class="bordered ? 'overflow-hidden rounded-2xl border border-line bg-surface shadow-card' : 'border-t border-line'" :aria-label="label">
    <slot />
  </ul>
</template>
