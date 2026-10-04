<script setup lang="ts">
defineProps<{ items: { title: string; body: string }[] }>()
const open = ref<number | null>(0)
const id = useId()
</script>

<template>
  <div class="divide-y divide-line rounded-2xl border border-line bg-surface shadow-card">
    <div v-for="(item, i) in items" :key="i">
      <h3>
        <button :id="`${id}-h${i}`" type="button" class="flex w-full items-center justify-between gap-4 px-5 py-4 text-left font-medium" :aria-expanded="open === i" :aria-controls="`${id}-p${i}`" @click="open = open === i ? null : i">
          {{ item.title }}
          <AcmeIcon name="chevronDown" :size="18" class="text-muted transition-transform" :class="open === i && 'rotate-180'" />
        </button>
      </h3>
      <AcmeCollapse :open="open === i">
        <div :id="`${id}-p${i}`" role="region" :aria-labelledby="`${id}-h${i}`" class="px-5 pb-4 text-sm leading-relaxed text-muted">{{ item.body }}</div>
      </AcmeCollapse>
    </div>
  </div>
</template>
