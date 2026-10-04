<script setup lang="ts">
withDefaults(defineProps<{ icon: string; size?: 'sm' | 'md' }>(), { size: 'sm' })
</script>

<template>
  <span class="grid shrink-0 place-items-center rounded-full bg-black/[.05] text-muted dark:bg-white/10" :class="size === 'sm' ? 'size-8' : 'size-10'">
    <AcmeIcon :name="icon" :size="size === 'sm' ? 16 : 18" />
  </span>
</template>
