<script setup lang="ts">
const props = withDefaults(defineProps<{ names: string[]; max?: number }>(), { max: 4 })
const shown = computed(() => props.names.slice(0, props.max))
const rest = computed(() => props.names.length - shown.value.length)
</script>

<template>
  <div class="flex -space-x-2">
    <AcmeAvatar v-for="n in shown" :key="n" :name="n" size="sm" class="ring-2 ring-surface" />
    <span v-if="rest > 0" class="grid size-8 place-items-center rounded-full bg-zinc-200 text-xs font-medium ring-2 ring-surface dark:bg-zinc-700">+{{ rest }}</span>
  </div>
</template>
