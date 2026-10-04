<script setup lang="ts">
// Animated height collapse using the grid-rows 0fr → 1fr technique.
defineProps<{ open: boolean }>()
</script>

<template>
  <div class="grid transition-[grid-template-rows] duration-200 ease-out" :class="open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'">
    <div class="min-h-0 overflow-hidden" :inert="!open"><slot /></div>
  </div>
</template>
