<script setup lang="ts">
// Coloured square app icon used in the sidebar groups.
withDefaults(defineProps<{ icon: string; color: string; size?: 'md' | 'lg' }>(), { size: 'md' })
</script>

<template>
  <span class="grid shrink-0 place-items-center text-white shadow-[inset_0_-1px_0_rgba(0,0,0,.15)]" :class="[color, size === 'lg' ? 'size-10 rounded-xl' : 'size-6 rounded-md']">
    <AcmeIcon :name="icon" :size="size === 'lg' ? 20 : 14" />
  </span>
</template>
