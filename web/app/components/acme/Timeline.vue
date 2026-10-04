<script setup lang="ts">
// Vertical timeline (changelogs, ticket history, order events).
defineProps<{ items: { title: string; time?: string; body?: string; icon?: string; tone?: string }[] }>()
</script>

<template>
  <ol class="relative space-y-6 border-l border-line pl-6">
    <li v-for="(i, n) in items" :key="n" class="relative">
      <span class="absolute -left-[2.05rem] grid size-6 place-items-center rounded-full ring-4 ring-panel" :class="i.tone ?? 'bg-surface border border-line text-muted'">
        <AcmeIcon v-if="i.icon" :name="i.icon" :size="12" /><span v-else class="size-1.5 rounded-full bg-current" />
      </span>
      <p class="text-sm"><b class="font-medium">{{ i.title }}</b><span v-if="i.time" class="ml-2 text-muted">{{ i.time }}</span></p>
      <p v-if="i.body" class="mt-1 text-sm text-muted">{{ i.body }}</p>
      <slot :item="i" />
    </li>
  </ol>
</template>
